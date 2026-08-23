<?php

namespace Tests\Feature\Inventory;

use App\Models\PurchaseLine;
use App\Models\StockAdjustmentLine;
use App\Models\Transaction;
use App\Models\TransactionSellLine;
use App\Models\TransactionSellLinesPurchaseLines;
use App\Services\StockService;
use App\Support\TransactionTypes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises the FIFO engine — the integrity core of the whole system.
 *
 * The invariant under test: `variation_location_details.qty_available` must
 * always equal (total purchased − total consumed) as recorded in the FIFO map.
 */
class FifoStockTest extends TestCase
{
    use DatabaseTransactions;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);
        $this->createTenant();
    }

    /* ================================================================
     | Helpers
     ================================================================ */

    /**
     * Record a received purchase of one product at a given unit cost.
     */
    private function purchase(int $variationId, int $productId, float $qty, float $unitCost): PurchaseLine
    {
        return DB::transaction(function () use ($variationId, $productId, $qty, $unitCost) {
            $transaction = Transaction::create([
                'business_id' => $this->business->id,
                'location_id' => $this->location->id,
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
                'product_id' => $productId,
                'variation_id' => $variationId,
                'quantity' => $qty,
                'purchase_price' => $unitCost,
                'purchase_price_inc_tax' => $unitCost,
                'item_tax' => 0,
            ]);

            $this->stock->adjustCachedQuantity(
                $this->location->id, $productId, $variationId, $qty
            );

            return $line;
        });
    }

    /**
     * Record a final sale and consume stock FIFO.
     *
     * @return array{line: TransactionSellLine, result: array}
     */
    private function sell(int $variationId, int $productId, float $qty, float $unitPrice = 20): array
    {
        return DB::transaction(function () use ($variationId, $productId, $qty, $unitPrice) {
            $transaction = Transaction::create([
                'business_id' => $this->business->id,
                'location_id' => $this->location->id,
                'type' => TransactionTypes::SELL,
                'status' => TransactionTypes::STATUS_FINAL,
                'payment_status' => TransactionTypes::DUE,
                'transaction_date' => now(),
                'total_before_tax' => $qty * $unitPrice,
                'final_total' => $qty * $unitPrice,
                'created_by' => $this->user->id,
            ]);

            $line = TransactionSellLine::create([
                'transaction_id' => $transaction->id,
                'product_id' => $productId,
                'variation_id' => $variationId,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'unit_price_inc_tax' => $unitPrice,
                'item_tax' => 0,
            ]);

            $result = $this->stock->consume(
                $variationId, $this->location->id, $qty, $line->id, 'sell'
            );

            $this->stock->adjustCachedQuantity(
                $this->location->id, $productId, $variationId, -$qty
            );

            return ['line' => $line, 'result' => $result];
        });
    }

    /* ================================================================
     | Tests
     ================================================================ */

    #[Test]
    public function it_consumes_the_oldest_lot_first_and_returns_weighted_cost(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $lot1 = $this->purchase($variation->id, $product->id, 10, 5.00);
        $lot2 = $this->purchase($variation->id, $product->id, 10, 7.00);

        // Sell 15 → all 10 of lot 1, then 5 of lot 2.
        $sale = $this->sell($variation->id, $product->id, 15);

        $this->assertSame(15.0, $sale['result']['allocated']);
        $this->assertSame(0.0, $sale['result']['shortfall']);

        // Cost = (10 x 5) + (5 x 7) = 85
        $this->assertSame(85.0, $sale['result']['cost']);

        $this->assertSame(10.0, (float) $lot1->fresh()->quantity_sold);
        $this->assertSame(5.0, (float) $lot2->fresh()->quantity_sold);

        // Two map rows, one per lot touched.
        $map = TransactionSellLinesPurchaseLines::where('sell_line_id', $sale['line']->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $map);
        $this->assertSame($lot1->id, $map[0]->purchase_line_id);
        $this->assertSame(10.0, (float) $map[0]->quantity);
        $this->assertSame($lot2->id, $map[1]->purchase_line_id);
        $this->assertSame(5.0, (float) $map[1]->quantity);
    }

    #[Test]
    public function the_stock_cache_agrees_with_the_fifo_map(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->purchase($variation->id, $product->id, 10, 5.00);
        $this->purchase($variation->id, $product->id, 10, 7.00);
        $this->sell($variation->id, $product->id, 15);

        $this->assertSame(5.0, $this->stock->currentStock($variation->id, $this->location->id));

        $reconcile = $this->stock->reconcile($variation->id, $this->location->id);

        $this->assertSame(5.0, $reconcile['cached']);
        $this->assertSame(5.0, $reconcile['fifo']);
        $this->assertSame(0.0, $reconcile['difference']);
    }

    #[Test]
    public function it_reports_a_shortfall_instead_of_silently_overselling(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->purchase($variation->id, $product->id, 5, 5.00);

        $sale = $this->sell($variation->id, $product->id, 8);

        $this->assertSame(5.0, $sale['result']['allocated']);
        $this->assertSame(3.0, $sale['result']['shortfall']);
        $this->assertSame(25.0, $sale['result']['cost']);
    }

    #[Test]
    public function releasing_a_sale_line_gives_the_quantity_back_to_its_lots(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $lot1 = $this->purchase($variation->id, $product->id, 10, 5.00);
        $lot2 = $this->purchase($variation->id, $product->id, 10, 7.00);

        $sale = $this->sell($variation->id, $product->id, 15);

        $released = DB::transaction(function () use ($sale, $product, $variation) {
            $qty = $this->stock->release($sale['line']->id, 'sell');

            $this->stock->adjustCachedQuantity(
                $this->location->id, $product->id, $variation->id, $qty
            );

            return $qty;
        });

        $this->assertSame(15.0, $released);
        $this->assertSame(0.0, (float) $lot1->fresh()->quantity_sold);
        $this->assertSame(0.0, (float) $lot2->fresh()->quantity_sold);

        $this->assertSame(
            0,
            TransactionSellLinesPurchaseLines::where('sell_line_id', $sale['line']->id)->count()
        );

        $this->assertSame(20.0, $this->stock->currentStock($variation->id, $this->location->id));
        $this->assertSame(0.0, $this->stock->reconcile($variation->id, $this->location->id)['difference']);
    }

    #[Test]
    public function a_partial_return_credits_the_newest_lot_first_and_keeps_the_mapping(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $lot1 = $this->purchase($variation->id, $product->id, 10, 5.00);
        $lot2 = $this->purchase($variation->id, $product->id, 10, 7.00);

        $sale = $this->sell($variation->id, $product->id, 15);

        // Return 6: reverses lot 2's 5 units, then 1 unit of lot 1.
        $returned = DB::transaction(function () use ($sale, $product, $variation) {
            $qty = $this->stock->returnToLots($sale['line']->id, 6, 'sell');

            $this->stock->adjustCachedQuantity(
                $this->location->id, $product->id, $variation->id, $qty
            );

            return $qty;
        });

        $this->assertSame(6.0, $returned);
        $this->assertSame(9.0, (float) $lot1->fresh()->quantity_sold);
        $this->assertSame(0.0, (float) $lot2->fresh()->quantity_sold);

        // The map rows survive, annotated with what came back.
        $map = TransactionSellLinesPurchaseLines::where('sell_line_id', $sale['line']->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $map);
        $this->assertSame(1.0, (float) $map[0]->qty_returned);
        $this->assertSame(5.0, (float) $map[1]->qty_returned);

        $this->assertSame(11.0, $this->stock->currentStock($variation->id, $this->location->id));
        $this->assertSame(0.0, $this->stock->reconcile($variation->id, $this->location->id)['difference']);
    }

    #[Test]
    public function an_explicitly_chosen_lot_jumps_the_fifo_queue(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $lot1 = $this->purchase($variation->id, $product->id, 10, 5.00);
        $lot2 = $this->purchase($variation->id, $product->id, 10, 7.00);

        // Ask for lot 2 explicitly (e.g. the cashier picked a batch number).
        $result = DB::transaction(function () use ($variation, $product, $lot2) {
            $transaction = Transaction::create([
                'business_id' => $this->business->id,
                'location_id' => $this->location->id,
                'type' => TransactionTypes::SELL,
                'status' => TransactionTypes::STATUS_FINAL,
                'payment_status' => TransactionTypes::DUE,
                'transaction_date' => now(),
                'final_total' => 0,
                'created_by' => $this->user->id,
            ]);

            $line = TransactionSellLine::create([
                'transaction_id' => $transaction->id,
                'product_id' => $product->id,
                'variation_id' => $variation->id,
                'quantity' => 4,
                'unit_price' => 20,
                'unit_price_inc_tax' => 20,
                'item_tax' => 0,
                'lot_no_line_id' => $lot2->id,
            ]);

            $out = $this->stock->consume(
                $variation->id, $this->location->id, 4, $line->id, 'sell', $lot2->id
            );

            $this->stock->adjustCachedQuantity(
                $this->location->id, $product->id, $variation->id, -4
            );

            return $out;
        });

        $this->assertSame(4.0, $result['allocated']);
        // Cost came from lot 2 at 7.00, not lot 1 at 5.00.
        $this->assertSame(28.0, $result['cost']);
        $this->assertSame(0.0, (float) $lot1->fresh()->quantity_sold);
        $this->assertSame(4.0, (float) $lot2->fresh()->quantity_sold);
    }

    #[Test]
    public function a_stock_adjustment_consumes_lots_and_is_tracked_separately(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $lot = $this->purchase($variation->id, $product->id, 10, 5.00);

        $result = DB::transaction(function () use ($variation, $product) {
            $transaction = Transaction::create([
                'business_id' => $this->business->id,
                'location_id' => $this->location->id,
                'type' => TransactionTypes::STOCK_ADJUSTMENT,
                'status' => TransactionTypes::STATUS_RECEIVED,
                'adjustment_type' => 'normal',
                'transaction_date' => now(),
                'final_total' => 0,
                'created_by' => $this->user->id,
            ]);

            $line = StockAdjustmentLine::create([
                'transaction_id' => $transaction->id,
                'product_id' => $product->id,
                'variation_id' => $variation->id,
                'quantity' => 3,
                'unit_price' => 5,
            ]);

            $out = $this->stock->consume(
                $variation->id, $this->location->id, 3, $line->id, 'stock_adjustment'
            );

            $this->stock->adjustCachedQuantity(
                $this->location->id, $product->id, $variation->id, -3
            );

            return $out;
        });

        $this->assertSame(3.0, $result['allocated']);

        $fresh = $lot->fresh();
        // Adjustments use their own counter, leaving quantity_sold untouched.
        $this->assertSame(3.0, (float) $fresh->quantity_adjusted);
        $this->assertSame(0.0, (float) $fresh->quantity_sold);

        $this->assertSame(7.0, $this->stock->currentStock($variation->id, $this->location->id));
        $this->assertSame(0.0, $this->stock->reconcile($variation->id, $this->location->id)['difference']);
    }

    #[Test]
    public function a_purchase_cannot_be_edited_below_what_was_already_sold(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $lot = $this->purchase($variation->id, $product->id, 10, 5.00);
        $this->sell($variation->id, $product->id, 6);

        $this->expectException(\RuntimeException::class);

        DB::transaction(fn () => $this->stock->reduceLotQuantity($lot->fresh(), 4));
    }
}
