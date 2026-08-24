<?php

namespace Tests\Feature\Inventory;

use App\Models\BusinessLocation;
use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Models\TransactionSellLine;
use App\Models\Variation;
use App\Services\OpeningStockService;
use App\Services\StockAdjustmentService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Support\TransactionTypes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three documents that move stock without selling it: adjustments,
 * transfers and opening stock.
 *
 * `FifoStockTest` exercises the engine underneath by calling `StockService`
 * directly. This exercises the documents that drive it, and the assertion that
 * matters most is the same one in every test here: after the movement,
 * `reconcile()['difference']` is zero. Two records have to agree — the cached
 * `qty_available` and the FIFO map — and they are updated by separate calls, so
 * every path that writes one and forgets the other is a silent divergence that
 * no screen would show and no error would report.
 *
 * The transfer tests carry a second invariant of their own: goods in transit are
 * counted at neither shop. The source cache drops when the van leaves, the
 * destination's lots exist but sit at `pending`, and `pending` is the status the
 * FIFO query excludes — so units on the road cannot be sold at either end.
 */
class StockMovementsTest extends TestCase
{
    use DatabaseTransactions;

    private StockService $stock;

    private StockAdjustmentService $adjustments;

    private StockTransferService $transfers;

    private OpeningStockService $opening;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);
        $this->adjustments = app(StockAdjustmentService::class);
        $this->transfers = app(StockTransferService::class);
        $this->opening = app(OpeningStockService::class);

        $this->createTenant();
    }

    /* ================================================================
     | Helpers
     ================================================================ */

    /**
     * A second shop in the same business — a transfer needs somewhere to go.
     */
    private function otherLocation(): BusinessLocation
    {
        return BusinessLocation::create([
            'business_id' => $this->business->id,
            'name' => 'Branch Store',
            'invoice_scheme_id' => $this->location->invoice_scheme_id,
            'invoice_layout_id' => $this->location->invoice_layout_id,
            'is_active' => true,
        ]);
    }

    /**
     * A received purchase at a location, so there is a lot to consume from.
     */
    private function purchase(
        Variation $variation,
        float $qty,
        float $unitCost,
        ?BusinessLocation $at = null
    ): PurchaseLine {
        $at ??= $this->location;

        return DB::transaction(function () use ($variation, $qty, $unitCost, $at) {
            $transaction = Transaction::create([
                'business_id' => $this->business->id,
                'location_id' => $at->id,
                'type' => TransactionTypes::PURCHASE,
                'status' => TransactionTypes::STATUS_RECEIVED,
                'payment_status' => TransactionTypes::DUE,
                'transaction_date' => now()->subDays(10 - PurchaseLine::count()),
                'total_before_tax' => $qty * $unitCost,
                'final_total' => $qty * $unitCost,
                'created_by' => $this->user->id,
            ]);

            $line = PurchaseLine::create([
                'transaction_id' => $transaction->id,
                'product_id' => $variation->product_id,
                'variation_id' => $variation->id,
                'quantity' => $qty,
                'purchase_price' => $unitCost,
                'purchase_price_inc_tax' => $unitCost,
                'item_tax' => 0,
            ]);

            $this->stock->adjustCachedQuantity(
                $at->id, $variation->product_id, $variation->id, $qty
            );

            return $line;
        });
    }

    /**
     * A final sale at a location. Returns what FIFO managed to allocate, which
     * is how these tests ask "could this stock actually be sold?".
     *
     * @return array{allocated: float, shortfall: float, cost: float}
     */
    private function sell(Variation $variation, float $qty, ?BusinessLocation $at = null): array
    {
        $at ??= $this->location;

        return DB::transaction(function () use ($variation, $qty, $at) {
            $transaction = Transaction::create([
                'business_id' => $this->business->id,
                'location_id' => $at->id,
                'type' => TransactionTypes::SELL,
                'status' => TransactionTypes::STATUS_FINAL,
                'payment_status' => TransactionTypes::DUE,
                'transaction_date' => now(),
                'final_total' => 0,
                'created_by' => $this->user->id,
            ]);

            $line = TransactionSellLine::create([
                'transaction_id' => $transaction->id,
                'product_id' => $variation->product_id,
                'variation_id' => $variation->id,
                'quantity' => $qty,
                'unit_price' => 20,
                'unit_price_inc_tax' => 20,
                'item_tax' => 0,
            ]);

            $result = $this->stock->consume($variation->id, $at->id, $qty, $line->id, 'sell');

            $this->stock->adjustCachedQuantity(
                $at->id, $variation->product_id, $variation->id, -$result['allocated']
            );

            return $result;
        });
    }

    private function assertReconciles(Variation $variation, float $expected, ?BusinessLocation $at = null): void
    {
        $at ??= $this->location;

        $reconcile = $this->stock->reconcile($variation->id, $at->id);

        $this->assertSame($expected, $reconcile['cached'], 'cached quantity at '.$at->name);
        $this->assertSame($expected, $reconcile['fifo'], 'FIFO position at '.$at->name);
        $this->assertSame(0.0, $reconcile['difference'], 'cache/FIFO divergence at '.$at->name);
    }

    /* ================================================================
     | Adjustments
     ================================================================ */

    #[Test]
    public function an_adjustment_writes_off_stock_at_what_those_units_cost(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $lot1 = $this->purchase($variation, 10, 5.00);
        $lot2 = $this->purchase($variation, 10, 7.00);

        $adjustment = $this->adjustments->create([
            'location_id' => $this->location->id,
            'adjustment_type' => 'normal',
            'created_by' => $this->user->id,
        ], [[
            'variation_id' => $variation->id,
            'quantity' => 12,
        ]]);

        // Oldest first: all 10 of lot 1, then 2 of lot 2.
        $this->assertSame(10.0, (float) $lot1->fresh()->quantity_adjusted);
        $this->assertSame(2.0, (float) $lot2->fresh()->quantity_adjusted);

        // Sales are a separate counter, and an adjustment must not touch it.
        $this->assertSame(0.0, (float) $lot1->fresh()->quantity_sold);

        /*
         * (10 x 5) + (2 x 7) = 64. Not 12 x 7 = 84 (the latest price), and not
         * 12 x 6 = 72 (the average) — the write-off is worth what those specific
         * units cost, which is the reason the document consumes lots at all.
         */
        $this->assertEqualsWithDelta(64.0, (float) $adjustment->final_total, 0.01);

        $this->assertReconciles($variation, 8.0);
    }

    #[Test]
    public function an_adjustment_cannot_write_off_more_than_the_location_holds(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->purchase($variation, 5, 5.00);

        try {
            $this->adjustments->create([
                'location_id' => $this->location->id,
                'adjustment_type' => 'normal',
                'created_by' => $this->user->id,
            ], [[
                'variation_id' => $variation->id,
                'quantity' => 8,
            ]]);

            $this->fail('Expected the adjustment to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('8', $e->getMessage());
        }

        /*
         * The point of the test is what is left behind. `consume()` had already
         * emptied the lot by the time the shortfall was noticed, so a partial
         * write here would leave five units unaccounted for and no document
         * saying where they went.
         */
        $this->assertSame(0, Transaction::where('type', TransactionTypes::STOCK_ADJUSTMENT)->count());
        $this->assertReconciles($variation, 5.0);
    }

    #[Test]
    public function deleting_an_adjustment_returns_the_units_to_their_lots(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $lot1 = $this->purchase($variation, 10, 5.00);
        $lot2 = $this->purchase($variation, 10, 7.00);

        $adjustment = $this->adjustments->create([
            'location_id' => $this->location->id,
            'created_by' => $this->user->id,
        ], [[
            'variation_id' => $variation->id,
            'quantity' => 12,
        ]]);

        $this->adjustments->delete($adjustment);

        $this->assertSame(0.0, (float) $lot1->fresh()->quantity_adjusted);
        $this->assertSame(0.0, (float) $lot2->fresh()->quantity_adjusted);
        $this->assertReconciles($variation, 20.0);
    }

    #[Test]
    public function editing_an_adjustment_reverses_the_whole_document_before_rewriting_it(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $lot1 = $this->purchase($variation, 10, 5.00);
        $lot2 = $this->purchase($variation, 10, 7.00);

        $adjustment = $this->adjustments->create([
            'location_id' => $this->location->id,
            'created_by' => $this->user->id,
        ], [[
            'variation_id' => $variation->id,
            'quantity' => 12,
        ]]);

        // Down to 3, which no per-line delta could work out: the original 12
        // spanned two lots at two different costs.
        $this->adjustments->update($adjustment, [
            'location_id' => $this->location->id,
        ], [[
            'variation_id' => $variation->id,
            'quantity' => 3,
        ]]);

        $this->assertSame(3.0, (float) $lot1->fresh()->quantity_adjusted);
        $this->assertSame(0.0, (float) $lot2->fresh()->quantity_adjusted);

        // 3 x 5 = 15: re-consumed from the oldest lot, so re-valued too.
        $this->assertEqualsWithDelta(15.0, (float) $adjustment->fresh()->final_total, 0.01);
        $this->assertReconciles($variation, 17.0);
    }

    /* ================================================================
     | Transfers
     ================================================================ */

    #[Test]
    public function a_completed_transfer_moves_stock_and_carries_the_cost_with_it(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);
        $branch = $this->otherLocation();

        $this->purchase($variation, 10, 5.00);

        $out = $this->transfers->create([
            'location_id' => $this->location->id,
            'transfer_location_id' => $branch->id,
            'status' => TransactionTypes::STATUS_COMPLETED,
            'shipping_charges' => 30,
            'created_by' => $this->user->id,
        ], [[
            'variation_id' => $variation->id,
            'quantity' => 4,
        ]]);

        $in = $out->transfer_child;

        // Two documents, one event: same reference, and the in-leg points back.
        $this->assertSame(TransactionTypes::SELL_TRANSFER, $out->type);
        $this->assertSame(TransactionTypes::PURCHASE_TRANSFER, $in->type);
        $this->assertSame($out->ref_no, $in->ref_no);
        $this->assertSame($out->id, $in->transfer_parent_id);
        $this->assertSame(TransactionTypes::STATUS_RECEIVED, $in->status);

        $this->assertReconciles($variation, 6.0);
        $this->assertReconciles($variation, 4.0, $branch);

        // The destination lot is created at the cost FIFO gave up at the source,
        // so the receiving shop's margins are computed on a real cost basis.
        $lot = $in->purchase_lines->first();
        $this->assertSame(5.0, (float) $lot->purchase_price_inc_tax);

        // Freight is a cost of the move, not of the goods: it lands on the
        // out-leg's total and never inside a unit price.
        $this->assertSame(20.0, (float) $out->total_before_tax);
        $this->assertSame(50.0, (float) $out->final_total);
        $this->assertSame(20.0, (float) $in->final_total);
    }

    #[Test]
    public function goods_in_transit_are_counted_at_neither_shop(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);
        $branch = $this->otherLocation();

        $this->purchase($variation, 10, 5.00);

        $out = $this->transfers->create([
            'location_id' => $this->location->id,
            'transfer_location_id' => $branch->id,
            'status' => TransactionTypes::STATUS_IN_TRANSIT,
            'created_by' => $this->user->id,
        ], [[
            'variation_id' => $variation->id,
            'quantity' => 4,
        ]]);

        // Gone from the source the moment the van leaves.
        $this->assertReconciles($variation, 6.0);

        // Present as a lot at the destination — it has to be, to hold the cost —
        // but pending, and therefore worth nothing to the destination yet.
        $in = $out->transfer_child;
        $this->assertSame(TransactionTypes::STATUS_PENDING, $in->status);
        $this->assertSame(1, $in->purchase_lines->count());
        $this->assertReconciles($variation, 0.0, $branch);

        // And not merely absent from the cache: FIFO refuses to hand the units
        // out, which is the invariant `pending` exists to enforce.
        $attempt = $this->sell($variation, 4, $branch);
        $this->assertSame(0.0, $attempt['allocated']);
        $this->assertSame(4.0, $attempt['shortfall']);
    }

    #[Test]
    public function receiving_a_transfer_books_the_stock_in_at_the_destination(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);
        $branch = $this->otherLocation();

        $this->purchase($variation, 10, 5.00);

        $out = $this->transfers->create([
            'location_id' => $this->location->id,
            'transfer_location_id' => $branch->id,
            'status' => TransactionTypes::STATUS_IN_TRANSIT,
            'created_by' => $this->user->id,
        ], [[
            'variation_id' => $variation->id,
            'quantity' => 4,
        ]]);

        $out = $this->transfers->markReceived($out);

        $this->assertSame(TransactionTypes::STATUS_COMPLETED, $out->status);
        $this->assertSame(TransactionTypes::STATUS_RECEIVED, $out->transfer_child->status);

        $this->assertReconciles($variation, 6.0);
        $this->assertReconciles($variation, 4.0, $branch);

        // Sellable now, and at the cost that travelled with it.
        $sale = $this->sell($variation, 4, $branch);
        $this->assertSame(4.0, $sale['allocated']);
        $this->assertSame(0.0, $sale['shortfall']);
        $this->assertSame(20.0, $sale['cost']);
    }

    #[Test]
    public function receiving_a_transfer_twice_is_refused_rather_than_counted_twice(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);
        $branch = $this->otherLocation();

        $this->purchase($variation, 10, 5.00);

        $out = $this->transfers->create([
            'location_id' => $this->location->id,
            'transfer_location_id' => $branch->id,
            'status' => TransactionTypes::STATUS_IN_TRANSIT,
            'created_by' => $this->user->id,
        ], [[
            'variation_id' => $variation->id,
            'quantity' => 4,
        ]]);

        $out = $this->transfers->markReceived($out);

        // A stale tab clicking "received" a second time.
        $this->expectException(\RuntimeException::class);

        try {
            $this->transfers->markReceived($out);
        } finally {
            $this->assertReconciles($variation, 4.0, $branch);
        }
    }

    #[Test]
    public function a_transfer_needs_two_different_locations(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->purchase($variation, 10, 5.00);

        $this->expectException(\RuntimeException::class);

        $this->transfers->create([
            'location_id' => $this->location->id,
            'transfer_location_id' => $this->location->id,
            'created_by' => $this->user->id,
        ], [[
            'variation_id' => $variation->id,
            'quantity' => 4,
        ]]);
    }

    #[Test]
    public function a_transfer_cannot_overdraw_the_source(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);
        $branch = $this->otherLocation();

        $this->purchase($variation, 3, 5.00);

        try {
            $this->transfers->create([
                'location_id' => $this->location->id,
                'transfer_location_id' => $branch->id,
                'created_by' => $this->user->id,
            ], [[
                'variation_id' => $variation->id,
                'quantity' => 5,
            ]]);

            $this->fail('Expected the transfer to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        /*
         * Unlike a POS sale, a transfer may not oversell: the units are claimed
         * to be arriving somewhere, so a counting error at the source would
         * become real quantity at the destination. Nothing may survive the
         * refusal at either end.
         */
        $this->assertSame(0, Transaction::where('type', TransactionTypes::SELL_TRANSFER)->count());
        $this->assertSame(0, Transaction::where('type', TransactionTypes::PURCHASE_TRANSFER)->count());
        $this->assertReconciles($variation, 3.0);
        $this->assertReconciles($variation, 0.0, $branch);
    }

    #[Test]
    public function deleting_an_in_transit_transfer_gives_the_stock_back_to_the_source_only(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);
        $branch = $this->otherLocation();

        $this->purchase($variation, 10, 5.00);

        $out = $this->transfers->create([
            'location_id' => $this->location->id,
            'transfer_location_id' => $branch->id,
            'status' => TransactionTypes::STATUS_IN_TRANSIT,
            'created_by' => $this->user->id,
        ], [[
            'variation_id' => $variation->id,
            'quantity' => 4,
        ]]);

        $this->transfers->delete($out);

        /*
         * The destination cache was never incremented, so taking the quantity
         * off it unconditionally would leave the branch holding minus four —
         * the one bug a symmetrical-looking delete invites.
         */
        $this->assertReconciles($variation, 10.0);
        $this->assertReconciles($variation, 0.0, $branch);

        $this->assertSame(0, Transaction::where('type', TransactionTypes::SELL_TRANSFER)->count());
        $this->assertSame(0, Transaction::where('type', TransactionTypes::PURCHASE_TRANSFER)->count());
    }

    #[Test]
    public function deleting_a_received_transfer_unwinds_both_halves(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);
        $branch = $this->otherLocation();

        $this->purchase($variation, 10, 5.00);

        $out = $this->transfers->create([
            'location_id' => $this->location->id,
            'transfer_location_id' => $branch->id,
            'status' => TransactionTypes::STATUS_COMPLETED,
            'created_by' => $this->user->id,
        ], [[
            'variation_id' => $variation->id,
            'quantity' => 4,
        ]]);

        $this->transfers->delete($out);

        $this->assertReconciles($variation, 10.0);
        $this->assertReconciles($variation, 0.0, $branch);
    }

    #[Test]
    public function a_transfer_cannot_be_deleted_once_the_destination_has_sold_the_goods(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);
        $branch = $this->otherLocation();

        $this->purchase($variation, 10, 5.00);

        $out = $this->transfers->create([
            'location_id' => $this->location->id,
            'transfer_location_id' => $branch->id,
            'status' => TransactionTypes::STATUS_COMPLETED,
            'created_by' => $this->user->id,
        ], [[
            'variation_id' => $variation->id,
            'quantity' => 4,
        ]]);

        $this->sell($variation, 2, $branch);

        // Unwinding this would have to unwind that sale too. The honest
        // correction is a transfer back the other way.
        $this->expectException(\RuntimeException::class);

        try {
            $this->transfers->delete($out);
        } finally {
            $this->assertReconciles($variation, 6.0);
            $this->assertReconciles($variation, 2.0, $branch);
        }
    }

    /* ================================================================
     | Opening stock
     ================================================================ */

    #[Test]
    public function opening_stock_creates_a_lot_that_can_be_sold_from(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $document = $this->opening->save(
            $product,
            $this->location->id,
            [$variation->id => 10],
            [$variation->id => 6],
            null,
            $this->user->id,
        );

        $this->assertSame(TransactionTypes::OPENING_STOCK, $document->type);
        $this->assertSame(TransactionTypes::STATUS_RECEIVED, $document->status);
        $this->assertEqualsWithDelta(60.0, (float) $document->final_total, 0.01);

        $this->assertReconciles($variation, 10.0);

        // Valued at the price it was stated with, not at the catalogue's.
        $sale = $this->sell($variation, 4);
        $this->assertSame(4.0, $sale['allocated']);
        $this->assertSame(24.0, $sale['cost']);
    }

    #[Test]
    public function restating_opening_stock_edits_the_same_lot_in_place(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $document = $this->opening->save(
            $product, $this->location->id, [$variation->id => 10], [$variation->id => 6],
            null, $this->user->id,
        );

        $lotId = $document->purchase_lines->first()->id;

        $this->opening->save(
            $product, $this->location->id, [$variation->id => 4], [$variation->id => 6],
            null, $this->user->id,
        );

        /*
         * The same lot row, not a replacement. An opening-stock line *is* a lot,
         * so deleting and recreating it would orphan every sale that had already
         * consumed from it.
         */
        $lot = PurchaseLine::find($lotId);
        $this->assertNotNull($lot);
        $this->assertSame(4.0, (float) $lot->quantity);

        $this->assertReconciles($variation, 4.0);
    }

    #[Test]
    public function opening_stock_cannot_be_cut_below_what_has_already_been_sold(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->opening->save(
            $product, $this->location->id, [$variation->id => 10], [$variation->id => 6],
            null, $this->user->id,
        );

        $this->sell($variation, 6);

        $this->expectException(\RuntimeException::class);

        try {
            $this->opening->save(
                $product, $this->location->id, [$variation->id => 4], [$variation->id => 6],
                null, $this->user->id,
            );
        } finally {
            // Refused, and the position is exactly what it was.
            $this->assertReconciles($variation, 4.0);
        }
    }

    #[Test]
    public function zeroing_opening_stock_withdraws_the_document(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->opening->save(
            $product, $this->location->id, [$variation->id => 10], [$variation->id => 6],
            null, $this->user->id,
        );

        // Nothing of it here is a statement in its own right, and the document
        // that said otherwise goes rather than lingering at zero.
        $result = $this->opening->save(
            $product, $this->location->id, [$variation->id => 0], [],
            null, $this->user->id,
        );

        $this->assertNull($result);
        $this->assertNull($this->opening->forProduct($product, $this->location->id));
        $this->assertSame(0, Transaction::where('type', TransactionTypes::OPENING_STOCK)->count());
        $this->assertReconciles($variation, 0.0);
    }

    #[Test]
    public function opening_stock_is_stated_per_location(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);
        $branch = $this->otherLocation();

        $this->opening->save(
            $product, $this->location->id, [$variation->id => 10], [$variation->id => 6],
            null, $this->user->id,
        );

        $this->opening->save(
            $product, $branch->id, [$variation->id => 3], [$variation->id => 6],
            null, $this->user->id,
        );

        // Two documents about two shelves, not one figure split between them.
        $this->assertSame(2, Transaction::where('type', TransactionTypes::OPENING_STOCK)->count());
        $this->assertReconciles($variation, 10.0);
        $this->assertReconciles($variation, 3.0, $branch);
    }

    #[Test]
    public function stock_documents_refuse_products_that_are_not_stock_tracked(): void
    {
        $product = $this->createProduct(['enable_stock' => 0, 'name' => 'Service']);
        $variation = $this->variationOf($product);
        $branch = $this->otherLocation();

        // Three documents, one rule: a quantity that is not tracked cannot be
        // written off, moved or opened — each refusal names the product.
        foreach ([
            fn () => $this->adjustments->create([
                'location_id' => $this->location->id,
                'created_by' => $this->user->id,
            ], [['variation_id' => $variation->id, 'quantity' => 1]]),

            fn () => $this->transfers->create([
                'location_id' => $this->location->id,
                'transfer_location_id' => $branch->id,
                'created_by' => $this->user->id,
            ], [['variation_id' => $variation->id, 'quantity' => 1]]),

            fn () => $this->opening->save(
                $product, $this->location->id, [$variation->id => 1], [], null, $this->user->id
            ),
        ] as $index => $attempt) {
            try {
                $attempt();
                $this->fail('Document '.$index.' should have been refused.');
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $this->assertSame(0, Transaction::whereIn('type', [
            TransactionTypes::STOCK_ADJUSTMENT,
            TransactionTypes::SELL_TRANSFER,
            TransactionTypes::OPENING_STOCK,
        ])->count());
    }
}
