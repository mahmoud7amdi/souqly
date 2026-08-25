<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductVariation;
use App\Models\Variation;
use App\Models\VariationGroupPrice;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Products, variations, pricing and SKU generation.
 */
class ProductService
{
    public function __construct(
        private FormattingService $format,
        private StockService $stock,
    ) {}

    /* ====================================================================
     | Creation
     ==================================================================== */

    /**
     * Create the single dummy variation a `single` product needs.
     *
     * @param  array<string, mixed>  $prices
     */
    public function createSingleVariation(Product $product, array $prices): Variation
    {
        $productVariation = ProductVariation::create([
            'product_id' => $product->id,
            'name' => 'DUMMY',
            'is_dummy' => 1,
        ]);

        return Variation::create(array_merge([
            'product_id' => $product->id,
            'product_variation_id' => $productVariation->id,
            'name' => 'DUMMY',
            'sub_sku' => $product->sku,
        ], $this->normalisePrices($product, $prices)));
    }

    /**
     * Create the variation groups and variations of a `variable` product.
     *
     * Also the append path: a shop that starts stocking XL later adds it here,
     * and nothing in this method touches what already exists. That is why the
     * sub-SKU counter starts from the variations already on the product instead
     * of from zero — restarting it would mint a second `SKU-1`, and a barcode
     * that resolves to two variations is worse than an ugly one.
     *
     * @param  array<int, array{name: string, variation_template_id?: int, variations: array<int, array<string, mixed>>}>  $groups
     * @return array<int, Variation>
     */
    public function createVariableVariations(Product $product, array $groups): array
    {
        $created = [];
        $index = $product->variations()->count();

        foreach ($groups as $group) {
            $productVariation = ProductVariation::create([
                'product_id' => $product->id,
                'variation_template_id' => $group['variation_template_id'] ?? null,
                'name' => $group['name'],
                'is_dummy' => 0,
            ]);

            foreach ($group['variations'] as $variation) {
                $index++;

                $created[] = Variation::create(array_merge([
                    'product_id' => $product->id,
                    'product_variation_id' => $productVariation->id,
                    'name' => $variation['name'],
                    'variation_value_id' => $variation['variation_value_id'] ?? null,
                    'sub_sku' => $variation['sub_sku']
                        ?? $this->generateSubSku($product->sku, $index),
                ], $this->normalisePrices($product, $variation)));
            }
        }

        return $created;
    }

    /**
     * Combo products hold their component list on the variation itself and
     * never carry stock of their own — the components are decremented.
     *
     * @param  array<int, array{variation_id: int, quantity: float, unit_id?: int}>  $components
     */
    public function createComboVariation(Product $product, array $components, array $prices): Variation
    {
        $variation = $this->createSingleVariation($product, $prices);

        $variation->combo_variations = array_map(fn ($c) => [
            'variation_id' => (int) $c['variation_id'],
            'quantity' => $this->format->numUf($c['quantity']),
            'unit_id' => $c['unit_id'] ?? null,
        ], $components);

        $variation->save();

        return $variation;
    }

    /* ====================================================================
     | Pricing
     ==================================================================== */

    /**
     * Fill in whichever of (purchase price, profit %, sell price) was omitted.
     *
     * @param  array<string, mixed>  $prices
     * @return array<string, float>
     */
    public function normalisePrices(Product $product, array $prices): array
    {
        $taxPercent = $this->taxPercentFor($product);
        $taxInclusive = $product->tax_type === 'inclusive';

        $purchase = $this->format->numUf($prices['default_purchase_price'] ?? 0);
        $dppIncTax = $this->format->numUf($prices['dpp_inc_tax'] ?? 0);

        // Whichever side was given, derive the other.
        if ($purchase > 0 && $dppIncTax <= 0) {
            $dppIncTax = $taxInclusive
                ? $purchase
                : round($purchase * (1 + $taxPercent / 100), 4);
        } elseif ($dppIncTax > 0 && $purchase <= 0) {
            $purchase = $taxInclusive
                ? round($dppIncTax / (1 + $taxPercent / 100), 4)
                : $dppIncTax;
        }

        $profitPercent = $this->format->numUf(
            $prices['profit_percent'] ?? session('business.default_profit_percent', 0)
        );

        $sell = $this->format->numUf($prices['default_sell_price'] ?? 0);

        if ($sell <= 0 && $purchase > 0) {
            $sell = round($purchase * (1 + $profitPercent / 100), 4);
        } elseif ($sell > 0 && $purchase > 0) {
            // Sell price given explicitly — recompute the implied margin.
            $profitPercent = round(($sell - $purchase) / $purchase * 100, 4);
        }

        $sellIncTax = round($sell * (1 + $taxPercent / 100), 4);

        return [
            'default_purchase_price' => $purchase,
            'dpp_inc_tax' => $dppIncTax,
            'profit_percent' => $profitPercent,
            'default_sell_price' => $sell,
            'sell_price_inc_tax' => $sellIncTax,
        ];
    }

    /**
     * Apply a new purchase cost to a variation according to the tenant's
     * `purchase_price_update_mode`, recording the change in price history.
     *
     * @return bool whether anything changed
     */
    public function applyPurchasePrice(
        Variation $variation,
        float $newCost,
        float $existingQty,
        ?int $transactionId = null,
        ?string $mode = null
    ): bool {
        $mode ??= session('business.purchase_price_update_mode', 'do_not_change');

        if ($mode === 'do_not_change' || $newCost <= 0) {
            return false;
        }

        $oldCost = (float) $variation->default_purchase_price;

        $resolved = $newCost;
        $details = null;

        if ($mode === 'average' && $existingQty > 0 && $oldCost > 0) {
            // Weighted average of existing stock at the old cost and the
            // incoming quantity at the new cost.
            $incomingQty = max(0.0, $existingQty);
            $resolved = round(
                (($existingQty * $oldCost) + ($incomingQty * $newCost)) / ($existingQty + $incomingQty),
                4
            );

            $details = sprintf(
                '((%s × %s) + (%s × %s)) ÷ %s = %s',
                $this->format->quantity($existingQty), $this->format->numF($oldCost),
                $this->format->quantity($incomingQty), $this->format->numF($newCost),
                $this->format->quantity($existingQty + $incomingQty),
                $this->format->numF($resolved)
            );
        }

        if (abs($resolved - $oldCost) < 0.0001) {
            return false;
        }

        $before = $variation->only([
            'default_purchase_price', 'dpp_inc_tax', 'default_sell_price',
            'sell_price_inc_tax', 'profit_percent',
        ]);

        $updated = $this->normalisePrices($variation->product, [
            'default_purchase_price' => $resolved,
            'profit_percent' => $variation->profit_percent,
        ]);

        $variation->fill($updated)->save();

        ProductPriceHistory::create([
            'variation_id' => $variation->id,
            'old_purchase_price' => $before['default_purchase_price'],
            'new_purchase_price' => $updated['default_purchase_price'],
            'old_dpp_inc_tax' => $before['dpp_inc_tax'],
            'new_dpp_inc_tax' => $updated['dpp_inc_tax'],
            'old_sell_price' => $before['default_sell_price'],
            'new_sell_price' => $updated['default_sell_price'],
            'old_sell_price_inc_tax' => $before['sell_price_inc_tax'],
            'new_sell_price_inc_tax' => $updated['sell_price_inc_tax'],
            'old_profit_percent' => $before['profit_percent'],
            'new_profit_percent' => $updated['profit_percent'],
            'change_type' => 'purchase',
            'change_reason' => __('lang_v1.price_updated_from_purchase', ['mode' => $mode]),
            'calculation_details' => $details,
            'transaction_id' => $transactionId,
            'created_by' => auth()->id() ?? $variation->product->created_by,
        ]);

        return true;
    }

    /**
     * Selling price for a variation, honouring a price group when given.
     */
    public function sellPriceFor(Variation $variation, ?int $priceGroupId = null): float
    {
        if (empty($priceGroupId)) {
            return (float) $variation->sell_price_inc_tax;
        }

        $groupPrice = VariationGroupPrice::where('variation_id', $variation->id)
            ->where('price_group_id', $priceGroupId)
            ->first();

        if (empty($groupPrice)) {
            return (float) $variation->sell_price_inc_tax;
        }

        $groupPrice->setRelation('variation', $variation);

        return $groupPrice->calculated_price;
    }

    /**
     * Best active discount for a variation at a location, if any.
     */
    public function discountFor(Variation $variation, int $locationId, ?int $priceGroupId = null): ?Discount
    {
        return Discount::current()
            ->where(function ($q) use ($locationId) {
                $q->where('location_id', $locationId)->orWhereNull('location_id');
            })
            ->where(function ($q) use ($variation) {
                $q->whereHas('variations', fn ($v) => $v->where('variations.id', $variation->id))
                    ->orWhere('brand_id', $variation->product->brand_id)
                    ->orWhere('category_id', $variation->product->category_id);
            })
            ->when(! empty($priceGroupId), fn ($q) => $q->where(function ($sub) use ($priceGroupId) {
                $sub->whereNull('spg')->orWhere('spg', $priceGroupId);
            }))
            ->orderByDesc('priority')
            ->first();
    }

    /* ====================================================================
     | SKU
     ==================================================================== */

    /**
     * Next auto SKU for the tenant, e.g. "SQ0042".
     */
    public function generateSku(?int $businessId = null): string
    {
        $businessId ??= Tenancy::id();

        $prefix = session('business.sku_prefix') ?: 'SQ';

        return DB::transaction(function () use ($businessId, $prefix) {
            $lastId = (int) Product::withoutGlobalScope(\App\Scopes\BusinessScope::class)
                ->where('business_id', $businessId)
                ->max('id');

            return $prefix.str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
        });
    }

    public function generateSubSku(string $parentSku, int $index): string
    {
        return $parentSku.'-'.$index;
    }

    /**
     * True when the SKU is already taken within the tenant.
     */
    public function skuExists(string $sku, ?int $ignoreProductId = null): bool
    {
        return Product::where('sku', $sku)
            ->when($ignoreProductId, fn ($q) => $q->where('id', '!=', $ignoreProductId))
            ->exists();
    }

    /* ====================================================================
     | Combo
     ==================================================================== */

    /**
     * Deepest possible quantity of a combo product, limited by its scarcest
     * component.
     */
    public function comboAvailableQuantity(Variation $combo, int $locationId): float
    {
        $components = (array) $combo->combo_variations;

        if (empty($components)) {
            return 0.0;
        }

        $possible = [];

        foreach ($components as $component) {
            $needed = (float) $component['quantity'];

            if ($needed <= 0) {
                continue;
            }

            $available = $this->stock->currentStock((int) $component['variation_id'], $locationId);
            $possible[] = floor($available / $needed);
        }

        return empty($possible) ? 0.0 : (float) min($possible);
    }

    /**
     * Tax percentage applying to a product (0 when untaxed).
     */
    public function taxPercentFor(Product $product): float
    {
        if (empty($product->tax)) {
            return 0.0;
        }

        return (float) ($product->product_tax->amount ?? 0);
    }
}
