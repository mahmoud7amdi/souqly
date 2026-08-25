<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\Variation;
use App\Models\VariationGroupPrice;
use App\Models\VariationLocationDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The snapshot the terminal keeps on the device.
 *
 * WHY THIS IS NOT `ProductController::getProducts()` WITH A HIGHER LIMIT
 *
 * They answer different questions. `getProducts()` answers "what matches what the
 * cashier just typed" — 25 rows, chosen by the database, one request per
 * keystroke-batch. This answers "what could the cashier possibly need for the rest
 * of the shift" — the whole sellable catalogue for one location, fetched once,
 * searched afterwards on the device.
 *
 * That difference changes the implementation, not just the limit:
 *
 *   `getProducts()` calls `StockService::currentStock()` and
 *   `ProductService::sellPriceFor()` per row. At 25 rows that is fine. At a
 *   catalogue's worth it is two queries per variation — a snapshot of 3,000
 *   products would be 6,000 round trips, which is not a slow endpoint, it is an
 *   endpoint that times out. Both are resolved here in one query each and joined
 *   in memory.
 *
 *   Rows carry a precomputed `search` string. Matching on the device has to be
 *   fast enough to run on every keystroke on a cheap Android tablet, and
 *   lower-casing four fields per row per keystroke is the kind of work that shows
 *   up as lag on exactly the hardware a shop buys.
 *
 * THE ROW SHAPE IS `getProducts()`'s, DELIBERATELY
 *
 * Same keys, same types, so `renderProducts()` in the POS cannot tell which
 * source answered and there is no second rendering path to keep in step. The two
 * extra keys — `search` and `name` — are additive; the grid ignores what it does
 * not read.
 */
class OfflineDataController extends Controller
{
    /**
     * The catalogue is capped. A snapshot has to fit in IndexedDB and cross a
     * shop's uplink, and a business with more sellable variations than this has
     * outgrown "cache everything" as a strategy — it needs a chosen subset, which
     * is a product decision and not one to make silently here. The response says
     * how many were left out so the terminal can say so too.
     */
    private const MAX_ROWS = 5000;

    /**
     * Everything the terminal needs to sell from one location without a server.
     */
    public function index(Request $request): JsonResponse
    {
        // The same gate as the terminal itself: this payload is the POS screen's
        // own data, so anyone who may open the screen may cache it, and anyone who
        // may not must not be able to read the catalogue through the back door.
        $this->permit('sell.create', 'direct_sell.access');

        $validated = $request->validate([
            'location_id' => 'nullable|integer|exists:business_locations,id',
            'price_group_id' => 'nullable|integer|exists:selling_price_groups,id',
        ]);

        $locationId = ($validated['location_id'] ?? null) ?: null;
        $priceGroupId = ($validated['price_group_id'] ?? null) ?: null;

        /*
         * A location the user may not access is refused rather than quietly
         * widened to "all locations". Silently answering a different question
         * would hand a branch-restricted cashier the head office's stock figures,
         * and they would have no way to tell that is what they were looking at.
         */
        $permitted = BusinessLocation::permittedLocations();

        if ($locationId && $permitted !== 'all' && ! in_array($locationId, $permitted, false)) {
            abort(403, __('lang_v1.unauthorized'));
        }

        $variations = Variation::query()
            ->with(['product.unit'])
            ->whereHas('product', function ($query) use ($locationId) {
                $query->where('is_inactive', 0)
                    ->where('not_for_selling', 0)
                    ->forLocation($locationId);
            })
            ->orderBy('product_id')
            ->limit(self::MAX_ROWS + 1)
            ->get();

        $truncated = $variations->count() > self::MAX_ROWS;
        $variations = $variations->take(self::MAX_ROWS);

        $stock = $this->stockFor($variations->pluck('id')->all(), $locationId);
        $groupPrices = $this->groupPricesFor($variations->pluck('id')->all(), $priceGroupId);
        $showsCost = $this->allows('view_purchase_price');

        /*
         * `$locationId` is in the capture list because `qty_available` below
         * distinguishes "no stock" from "no location asked about" — see the
         * ternary. Its absence was an `Undefined variable` that only fires once
         * the closure actually runs, so every snapshot test with an empty
         * catalogue passed and only the three with products in them failed.
         */
        $rows = $variations->map(function (Variation $variation) use (
            $stock, $groupPrices, $priceGroupId, $showsCost, $locationId
        ) {
            $product = $variation->product;
            $name = $variation->full_name;

            return [
                'variation_id' => $variation->id,
                'product_id' => $variation->product_id,
                'text' => $name,
                'name' => $name,
                'sku' => $variation->sub_sku,
                'unit' => $product->unit->short_name ?? '',
                'enable_stock' => (bool) $product->enable_stock,
                'qty_available' => $locationId
                    ? round((float) ($stock[$variation->id] ?? 0), 4)
                    : null,
                'selling_price' => $this->priceFor($variation, $groupPrices, $priceGroupId),
                'purchase_price' => $showsCost ? (float) $variation->dpp_inc_tax : null,
                'tax_id' => $product->tax,
                'tax_type' => $product->tax_type,
                'image_url' => $product->hasImage() ? $product->image_url : null,

                // Precomputed haystack. Mirrors the four columns the server
                // searches — variation SKU, variation name, product name, product
                // SKU — so the device's matching is over the same facts even
                // though it matches them more loosely.
                'search' => mb_strtolower(implode(' ', array_filter([
                    $variation->sub_sku,
                    $variation->name === 'DUMMY' ? null : $variation->name,
                    $product->name,
                    $product->sku,
                ]))),
            ];
        })->values();

        return response()->json([
            'location_id' => $locationId,
            'price_group_id' => $priceGroupId,
            'taken_at' => now()->toIso8601String(),
            'truncated' => $truncated,
            'count' => $rows->count(),
            'products' => $rows,
        ]);
    }

    /**
     * Available quantity per variation, in one grouped query.
     *
     * Sums rather than reads a single row because a variation has one
     * `variation_location_details` row per location, and the no-location case
     * ("all branches") is the sum across them — which is what
     * `StockService::currentStock()` does when its location argument is null.
     *
     * @param  array<int, int>  $variationIds
     * @return array<int, float>
     */
    private function stockFor(array $variationIds, ?int $locationId): array
    {
        if (empty($variationIds)) {
            return [];
        }

        return VariationLocationDetails::query()
            ->selectRaw('variation_id, SUM(qty_available) AS qty')
            ->whereIn('variation_id', $variationIds)
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->groupBy('variation_id')
            ->pluck('qty', 'variation_id')
            ->map(fn ($qty) => (float) $qty)
            ->all();
    }

    /**
     * Price-group overrides per variation, in one query.
     *
     * @param  array<int, int>  $variationIds
     * @return array<int, VariationGroupPrice>
     */
    private function groupPricesFor(array $variationIds, ?int $priceGroupId): array
    {
        if (empty($priceGroupId) || empty($variationIds)) {
            return [];
        }

        return VariationGroupPrice::query()
            ->whereIn('variation_id', $variationIds)
            ->where('price_group_id', $priceGroupId)
            ->get()
            ->keyBy('variation_id')
            ->all();
    }

    /**
     * The selling price, with the group override applied.
     *
     * Goes through `VariationGroupPrice::calculated_price` — the same accessor
     * `ProductService::sellPriceFor()` uses — rather than repeating its
     * fixed-versus-percentage arithmetic. A second copy of that rule is a second
     * place for it to drift, and the symptom would be a terminal that charges a
     * different price offline than online, which is the worst possible symptom.
     *
     * @param  array<int, VariationGroupPrice>  $groupPrices
     */
    private function priceFor(Variation $variation, array $groupPrices, ?int $priceGroupId): float
    {
        if (empty($priceGroupId) || ! isset($groupPrices[$variation->id])) {
            return (float) $variation->sell_price_inc_tax;
        }

        $groupPrice = $groupPrices[$variation->id];

        // The accessor reads `variation->sell_price_inc_tax` for a percentage
        // markup; setting the relation keeps that a hit on the row already loaded
        // instead of a lazy query per variation.
        $groupPrice->setRelation('variation', $variation);

        return $groupPrice->calculated_price;
    }
}
