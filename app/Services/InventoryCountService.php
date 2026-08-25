<?php

namespace App\Services;

use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Models\Variation;
use App\Modules\InventoryManagement\Models\Inventory;
use App\Modules\InventoryManagement\Models\InventoryProduct;
use App\Support\Tenancy;
use App\Support\TransactionTypes;
use Illuminate\Support\Facades\DB;

/**
 * Physical stock counts, and the posting of what they find.
 *
 * A count is the only routine in the system that compares the books against
 * reality, so it is also the only one that can move stock in *either* direction.
 * Everything else has a natural sign: a purchase adds, a sale subtracts, an
 * adjustment writes off. A count says "the shelf holds nine and you think it
 * holds seven", and both halves of that have to land somewhere.
 *
 * THE TWO DIRECTIONS TAKE DIFFERENT PATHS, ON PURPOSE
 *
 * A shortage is a write-off. It has an exact cost — the FIFO lots the missing
 * units came from — and there is already a service that removes stock at exactly
 * that cost, records which lots it took from, and refuses to go past zero. So a
 * shortage becomes a `stock_adjustment` through {@see StockAdjustmentService},
 * which means it also shows up on the Stock Adjustments screen with its own SA
 * reference. An auditor asking "what did we write off this quarter" gets one
 * answer, not two.
 *
 * A surplus has no such path, because it *creates* stock, and created stock needs
 * a lot to carry its cost. It becomes a `stock_count` document holding real
 * `purchase_lines` — see {@see TransactionTypes::STOCK_COUNT} for why that type
 * had to exist rather than reusing `purchase` (which would fabricate a supplier
 * payable) or `opening_stock` (which would claim the stock was there on day one).
 *
 * WHAT A FOUND UNIT COSTS
 *
 * `variations.dpp_inc_tax` — the default purchase price including tax. It is a
 * stated figure rather than a derived one, and that is the honest position: the
 * business does not know what these specific units cost, because it does not know
 * where they came from. `dpp_inc_tax` is also what the stock report already values
 * on-hand quantity at, so a count does not change what the same stock is worth
 * the moment it is recorded. Where it is zero — a product never purchased — the
 * found units cost zero, which reads as "unknown" in every margin downstream and
 * is preferable to a guess that reads as a fact.
 *
 * WHY THE WHOLE COUNT POSTS AT ONCE
 *
 * `close()` runs in one transaction over every unprocessed line. A count that
 * posted line by line and failed halfway would leave the books in a state that is
 * neither the old truth nor the new one, and the line that failed is exactly the
 * one nobody would look at. `InventoryProduct.transaction_id` is what marks a
 * line as posted, so re-closing is a no-op on lines already carrying one and the
 * operation is safe to retry.
 */
class InventoryCountService
{
    public function __construct(
        private StockService $stock,
        private StockAdjustmentService $adjustments,
        private ReferenceService $references,
        private FormattingService $format,
    ) {}

    /* ====================================================================
     | The count itself
     ==================================================================== */

    /**
     * Open a count for a location.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Inventory
    {
        return Inventory::create([
            'branch_id' => (int) $data['branch_id'],
            'name' => $data['name'],
            'status' => false,
            'end_date' => $data['end_date'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Inventory $count, array $data): Inventory
    {
        $count->fill([
            'name' => $data['name'],
            'end_date' => $data['end_date'] ?? null,
        ]);

        // The branch may only move while nothing has been posted. Afterwards the
        // documents are hung on the old location and moving the count would make
        // it describe stock it never touched.
        if (! $count->processedLines()->exists() && ! empty($data['branch_id'])) {
            $count->branch_id = (int) $data['branch_id'];
        }

        $count->save();

        return $count;
    }

    /**
     * Record a counted quantity for one variation.
     *
     * The book quantity is read *now* rather than taken from the request, so the
     * difference is against what the system actually holds at the moment of
     * counting and not against a number that was on screen ten minutes ago.
     */
    public function countLine(Inventory $count, int $variationId, float $counted): InventoryProduct
    {
        $variation = Variation::with('product')->findOrFail($variationId);

        if (! $variation->product->enable_stock) {
            throw new \RuntimeException(__('lang_v1.cannot_count_untracked_product', [
                'product' => $variation->full_name,
            ]));
        }

        $book = $this->stock->currentStock($variationId, (int) $count->branch_id);
        $difference = round($counted - $book, 4);

        $line = $count->lines()
            ->where('variation_id', $variationId)
            ->whereNull('transaction_id')
            ->first();

        $attributes = [
            'product_id' => $variation->product_id,
            'variation_id' => $variationId,

            // `qty_before` is a varchar in the schema — a legacy wart carried
            // over deliberately (NOTES §17). Cast on the way in so it at least
            // holds a canonical number rather than whatever the locale produced.
            'qty_before' => (string) round($book, 4),
            'amount_after_inventory' => $counted,
            'Amount_difference' => $difference,
            'inventory_type' => $difference > 0 ? 'surplus' : ($difference < 0 ? 'shortage' : 'match'),
        ];

        if (! empty($line)) {
            $line->update($attributes);

            return $line;
        }

        return $count->lines()->create($attributes);
    }

    public function removeLine(InventoryProduct $line): void
    {
        if ($line->isProcessed()) {
            throw new \RuntimeException(__('lang_v1.cannot_remove_posted_count_line'));
        }

        $line->delete();
    }

    /* ====================================================================
     | Posting
     ==================================================================== */

    /**
     * Post every unposted line and close the count.
     *
     * @return array{shortage: ?Transaction, surplus: ?Transaction, lines: int}
     */
    public function close(Inventory $count): array
    {
        return DB::transaction(function () use ($count) {
            $lines = $count->unprocessedLines()->with('variation.product')->get();

            $shortages = $lines->filter(fn ($line) => $line->Amount_difference < 0);
            $surpluses = $lines->filter(fn ($line) => $line->Amount_difference > 0);

            $shortageDoc = $shortages->isNotEmpty()
                ? $this->postShortages($count, $shortages)
                : null;

            $surplusDoc = $surpluses->isNotEmpty()
                ? $this->postSurpluses($count, $surpluses)
                : null;

            /*
             * Lines that came out level are marked against whichever document the
             * count produced, or left alone if it produced none. A counted line
             * that matched is still a fact worth keeping — it says someone looked
             * at that shelf — so it is never deleted, only never posted.
             */
            $count->status = true;
            $count->end_date = $count->end_date ?: now();
            $count->save();

            return [
                'shortage' => $shortageDoc,
                'surplus' => $surplusDoc,
                'lines' => $shortages->count() + $surpluses->count(),
            ];
        });
    }

    /**
     * Route shortages through the write-off service.
     *
     * `StockAdjustmentService::create()` opens its own DB transaction; nesting is
     * fine — Laravel turns the inner one into a savepoint, and `close()`'s
     * transaction is what actually commits.
     *
     * @param  \Illuminate\Support\Collection<int, InventoryProduct>  $lines
     */
    protected function postShortages(Inventory $count, $lines): Transaction
    {
        $document = $this->adjustments->create(
            [
                'location_id' => (int) $count->branch_id,
                'transaction_date' => $count->end_date ?: now(),
                'adjustment_type' => 'normal',
                'additional_notes' => __('lang_v1.raised_by_stock_count', ['count' => $count->name]),
            ],
            $lines->map(fn (InventoryProduct $line) => [
                'variation_id' => (int) $line->variation_id,

                // The adjustment service takes a positive quantity to remove;
                // the difference is negative by definition here.
                'quantity' => abs((float) $line->Amount_difference),
            ])->values()->all()
        );

        InventoryProduct::whereIn('id', $lines->pluck('id'))
            ->update(['transaction_id' => $document->id]);

        return $document;
    }

    /**
     * Create the found-stock document and its lots.
     *
     * @param  \Illuminate\Support\Collection<int, InventoryProduct>  $lines
     */
    protected function postSurpluses(Inventory $count, $lines): Transaction
    {
        $document = Transaction::create([
            'business_id' => Tenancy::id(),
            'location_id' => (int) $count->branch_id,
            'type' => TransactionTypes::STOCK_COUNT,
            'status' => TransactionTypes::STATUS_RECEIVED,
            'payment_status' => TransactionTypes::PAID,
            'ref_no' => $this->references->generate('stock_count'),
            'transaction_date' => $count->end_date ?: now(),
            'created_by' => auth()->id(),
            'final_total' => 0,
            'additional_notes' => __('lang_v1.raised_by_stock_count', ['count' => $count->name]),
        ]);

        $total = 0.0;

        foreach ($lines as $line) {
            $variation = $line->variation;

            if (empty($variation) || ! $variation->product->enable_stock) {
                throw new \RuntimeException(__('lang_v1.cannot_count_untracked_product', [
                    'product' => $variation->full_name ?? '',
                ]));
            }

            $quantity = (float) $line->Amount_difference;
            $price = round((float) $variation->dpp_inc_tax, 4);

            // Same shape as an opening-stock lot, because it is the same kind of
            // thing: a lot whose cost is stated rather than invoiced.
            PurchaseLine::create([
                'transaction_id' => $document->id,
                'product_id' => (int) $line->product_id,
                'variation_id' => (int) $line->variation_id,
                'quantity' => $quantity,
                'pp_without_discount' => $price,
                'purchase_price' => $price,
                'purchase_price_inc_tax' => $price,
                'item_tax' => 0,
            ]);

            $this->stock->adjustCachedQuantity(
                (int) $count->branch_id,
                (int) $line->product_id,
                (int) $line->variation_id,
                $quantity
            );

            $total = round($total + ($quantity * $price), 4);

            $line->transaction_id = $document->id;
            $line->save();
        }

        $document->total_before_tax = $total;
        $document->final_total = $total;
        $document->save();

        return $document;
    }

    /* ====================================================================
     | Delete
     ==================================================================== */

    /**
     * Delete a count.
     *
     * Refuses once anything has posted. The documents a count raises are ordinary
     * stock documents that later sales may have consumed from, and there is no
     * version of "delete the count" that can safely reach through them. Deleting
     * the count while leaving its documents would be worse still: stock moved
     * with nothing on file saying why.
     */
    public function delete(Inventory $count): void
    {
        if ($count->processedLines()->exists()) {
            throw new \RuntimeException(__('lang_v1.cannot_delete_posted_count'));
        }

        DB::transaction(function () use ($count) {
            $count->lines()->delete();
            $count->delete();
        });
    }

    /* ====================================================================
     | Figures for the screens
     ==================================================================== */

    /**
     * Headline figures for one count.
     *
     * @return array{lines: int, surplus_qty: float, shortage_qty: float, posted: int, pending: int}
     */
    public function summary(Inventory $count): array
    {
        $lines = $count->lines()->get(['Amount_difference', 'transaction_id']);

        return [
            'lines' => $lines->count(),
            'surplus_qty' => round((float) $lines->where('Amount_difference', '>', 0)->sum('Amount_difference'), 4),
            'shortage_qty' => round((float) abs($lines->where('Amount_difference', '<', 0)->sum('Amount_difference')), 4),
            'posted' => $lines->whereNotNull('transaction_id')->count(),
            'pending' => $lines->whereNull('transaction_id')->count(),
        ];
    }
}
