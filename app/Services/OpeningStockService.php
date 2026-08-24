<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Support\Tenancy;
use App\Support\TransactionTypes;
use Illuminate\Support\Facades\DB;

/**
 * Opening stock — what was on the shelf before the system started counting.
 *
 * Every other stock document describes an event: a delivery arrived, a customer
 * bought something, a box was dropped. Opening stock describes a starting
 * position, and it exists as a real document with real lots for one reason: a
 * quantity with no lot behind it is a quantity with no cost behind it, and the
 * first sale of such a unit consumes nothing, books zero cost and reports the
 * whole selling price as profit. So the shape here is deliberately identical to
 * a purchase — one `opening_stock` transaction holding one `purchase_lines` row
 * per variation, each carrying the price the goods were valued at.
 *
 * ONE DOCUMENT PER PRODUCT PER LOCATION, and it is edited in place rather than
 * superseded. `opening_stock_product_id` on the transaction is what makes the
 * pairing findable, and the screen is a form over that pairing: open a product
 * at a location, see the quantities that are there, change them, save. There is
 * no create-versus-edit distinction because "this product's opening position at
 * this shop" is a single fact that either has been stated or has not.
 *
 * PAID, NOT DUE. Opening stock is not a debt to anybody — nobody is owed money
 * for the goods that were already yours on day one. Leaving it `due` would
 * invent a supplier balance that no payment screen could ever settle.
 *
 * SHRINKING IS GUARDED, NOT FORBIDDEN. Lowering an opening quantity is a
 * legitimate correction of a mis-count, so it is allowed — right down to zero,
 * which removes the line. What it may not do is go below what has already been
 * sold out of that lot: those sales point at it, and shrinking the lot under
 * them would leave the FIFO map claiming more was consumed than ever existed.
 * `StockService::reduceLotQuantity()` is what refuses, and it names the
 * quantity already gone so the person can see how far down they may go.
 */
class OpeningStockService
{
    public function __construct(
        private StockService $stock,
        private FormattingService $format,
    ) {}

    /* ====================================================================
     | Read
     ==================================================================== */

    /**
     * The opening-stock document for one product at one location, if it exists.
     */
    public function forProduct(Product $product, int $locationId): ?Transaction
    {
        return Transaction::query()
            ->ofType(TransactionTypes::OPENING_STOCK)
            ->where('opening_stock_product_id', $product->id)
            ->where('location_id', $locationId)
            ->with('purchase_lines')
            ->first();
    }

    /* ====================================================================
     | Write
     ==================================================================== */

    /**
     * State (or restate) a product's opening position at one location.
     *
     * `$quantities` and `$prices` are keyed by variation id. A variation absent
     * from `$quantities`, or present with zero, means "none of this here" — which
     * removes its line if one existed. Location is a key rather than a field: to
     * move opening stock between shops, zero it at one and enter it at the other,
     * because that is two separate statements about two separate shelves.
     *
     * @param  array<int, mixed>  $quantities  variation id => quantity
     * @param  array<int, mixed>  $prices      variation id => unit cost
     */
    public function save(
        Product $product,
        int $locationId,
        array $quantities,
        array $prices = [],
        mixed $date = null,
        ?int $createdBy = null,
    ): ?Transaction {
        $createdBy ??= auth()->id();

        return DB::transaction(function () use ($product, $locationId, $quantities, $prices, $date, $createdBy) {
            if (! $product->enable_stock) {
                throw new \RuntimeException(__('lang_v1.cannot_open_stock_untracked_product', [
                    'product' => $product->name,
                ]));
            }

            $document = $this->forProduct($product, $locationId);

            $product->loadMissing('variations');

            // Nothing on file and nothing being asked for: say so rather than
            // writing an empty document that the stock reports would then have
            // to carry around.
            $wanted = collect($product->variations)->contains(
                fn ($variation) => $this->format->numUf($quantities[$variation->id] ?? 0) > 0
            );

            if (empty($document) && ! $wanted) {
                throw new \RuntimeException(__('lang_v1.nothing_to_open_stock_with'));
            }

            if (empty($document)) {
                $document = Transaction::create([
                    'business_id' => Tenancy::id(),
                    'location_id' => $locationId,
                    'type' => TransactionTypes::OPENING_STOCK,
                    'status' => TransactionTypes::STATUS_RECEIVED,
                    'payment_status' => TransactionTypes::PAID,
                    'opening_stock_product_id' => $product->id,
                    'transaction_date' => $date ?: now(),
                    'created_by' => $createdBy,
                    'final_total' => 0,
                ]);
            } elseif (! empty($date)) {
                $document->transaction_date = $date;
                $document->save();
            }

            $existing = $document->purchase_lines->keyBy('variation_id');

            foreach ($product->variations as $variation) {
                $quantity = $this->format->numUf($quantities[$variation->id] ?? 0);

                $price = array_key_exists($variation->id, $prices)
                    ? $this->format->numUf($prices[$variation->id])
                    : (float) $variation->default_purchase_price;

                $this->syncVariation(
                    $document,
                    $product->id,
                    (int) $variation->id,
                    $quantity,
                    $price,
                    $existing->get($variation->id)
                );
            }

            // Everything zeroed out: the statement "this product has an opening
            // position here" has been withdrawn, so the document goes with it.
            if ($document->purchase_lines()->count() === 0) {
                $document->delete();

                return null;
            }

            return $this->recalculateTotals($document)->fresh('purchase_lines');
        });
    }

    /**
     * Withdraw a product's opening position at a location entirely.
     */
    public function delete(Product $product, int $locationId): void
    {
        DB::transaction(function () use ($product, $locationId) {
            $document = $this->forProduct($product, $locationId);

            if (empty($document)) {
                return;
            }

            foreach ($document->purchase_lines as $lot) {
                // Refuses if any of it has been sold — see the class comment.
                $this->stock->reduceLotQuantity($lot, 0);

                $this->stock->adjustCachedQuantity(
                    $locationId, $lot->product_id, $lot->variation_id, -(float) $lot->quantity
                );

                $lot->delete();
            }

            $document->delete();
        });
    }

    /* ====================================================================
     | Internals
     ==================================================================== */

    /**
     * Bring one variation's lot into line with the quantity being asked for.
     *
     * Written as a delta rather than delete-and-recreate, which is the opposite
     * of the choice `StockAdjustmentService` makes — and for the opposite reason.
     * An adjustment's lines only *point at* lots, so reversing them is cheap and
     * exact; an opening-stock line *is* a lot, and destroying it would orphan
     * every sale that has consumed from it. Editing it in place keeps those
     * references intact, and the shrink guard is what keeps the edit honest.
     */
    protected function syncVariation(
        Transaction $document,
        int $productId,
        int $variationId,
        float $quantity,
        float $price,
        ?PurchaseLine $lot,
    ): void {
        if (empty($lot)) {
            if ($quantity <= 0) {
                return;
            }

            PurchaseLine::create([
                'transaction_id' => $document->id,
                'product_id' => $productId,
                'variation_id' => $variationId,
                'quantity' => $quantity,
                'pp_without_discount' => $price,
                'purchase_price' => $price,
                'purchase_price_inc_tax' => $price,
                'item_tax' => 0,
            ]);

            $this->stock->adjustCachedQuantity(
                $document->location_id, $productId, $variationId, $quantity
            );

            return;
        }

        $before = (float) $lot->quantity;
        $delta = round($quantity - $before, 4);

        if ($quantity <= 0) {
            $this->stock->reduceLotQuantity($lot, 0);

            $this->stock->adjustCachedQuantity(
                $document->location_id, $productId, $variationId, -$before
            );

            $lot->delete();

            return;
        }

        /*
         * Both directions go through reduceLotQuantity when shrinking so the
         * "already sold" guard cannot be skipped; growing needs no guard, since
         * more stock can never invalidate a consumption that already happened.
         */
        if ($delta < 0) {
            $this->stock->reduceLotQuantity($lot, $quantity);
        } elseif ($delta > 0) {
            $lot->quantity = $quantity;
        }

        $lot->pp_without_discount = $price;
        $lot->purchase_price = $price;
        $lot->purchase_price_inc_tax = $price;
        $lot->save();

        if (abs($delta) > 0.0001) {
            $this->stock->adjustCachedQuantity(
                $document->location_id, $productId, $variationId, $delta
            );
        }
    }

    /**
     * Value the opening position at the prices it was entered with.
     */
    public function recalculateTotals(Transaction $document): Transaction
    {
        $total = 0.0;

        foreach ($document->purchase_lines()->get() as $lot) {
            $total += (float) $lot->quantity * (float) $lot->purchase_price_inc_tax;
        }

        $document->total_before_tax = round($total, 4);
        $document->final_total = round($total, 4);
        $document->save();

        return $document;
    }
}
