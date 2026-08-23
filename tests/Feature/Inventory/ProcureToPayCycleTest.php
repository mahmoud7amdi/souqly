<?php

namespace Tests\Feature\Inventory;

use App\Models\Contact;
use App\Models\Transaction;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use App\Services\SellService;
use App\Services\StockService;
use App\Support\TransactionTypes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end procure-to-pay: purchase → sale → payment → return, asserting the
 * stock cache, the FIFO map, the totals and the payment status all stay
 * consistent through the whole cycle.
 */
class ProcureToPayCycleTest extends TestCase
{
    use DatabaseTransactions;

    private PurchaseService $purchases;

    private SellService $sells;

    private PaymentService $payments;

    private StockService $stock;

    private Contact $supplier;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchases = app(PurchaseService::class);
        $this->sells = app(SellService::class);
        $this->payments = app(PaymentService::class);
        $this->stock = app(StockService::class);

        $this->createTenant();
        $this->actingAs($this->user);

        $this->supplier = Contact::create([
            'business_id' => $this->business->id,
            'type' => 'supplier',
            'name' => 'Nile Traders',
            'supplier_business_name' => 'Nile Traders',
            'created_by' => $this->user->id,
        ]);

        $this->customer = Contact::create([
            'business_id' => $this->business->id,
            'type' => 'customer',
            'name' => 'Ahmed Hassan',
            'created_by' => $this->user->id,
            'credit_limit' => 1000,
        ]);
    }

    #[Test]
    public function a_received_purchase_adds_stock_and_sets_totals(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $purchase = $this->purchases->create(
            [
                'location_id' => $this->location->id,
                'contact_id' => $this->supplier->id,
                'status' => TransactionTypes::STATUS_RECEIVED,
                'shipping_charges' => 50,
            ],
            [[
                'variation_id' => $variation->id,
                'quantity' => 20,
                'purchase_price' => 10,
                'purchase_price_inc_tax' => 10,
            ]]
        );

        $this->assertSame(TransactionTypes::PURCHASE, $purchase->type);
        $this->assertSame(200.0, (float) $purchase->total_before_tax);
        // 200 goods + 50 shipping
        $this->assertSame(250.0, (float) $purchase->final_total);
        $this->assertSame(TransactionTypes::DUE, $purchase->payment_status);

        $this->assertSame(20.0, $this->stock->currentStock($variation->id, $this->location->id));
        $this->assertNotEmpty($purchase->ref_no);
    }

    #[Test]
    public function a_pending_purchase_does_not_move_stock_until_received(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $purchase = $this->purchases->create(
            [
                'location_id' => $this->location->id,
                'contact_id' => $this->supplier->id,
                'status' => TransactionTypes::STATUS_PENDING,
            ],
            [[
                'variation_id' => $variation->id,
                'quantity' => 20,
                'purchase_price' => 10,
                'purchase_price_inc_tax' => 10,
            ]]
        );

        $this->assertSame(0.0, $this->stock->currentStock($variation->id, $this->location->id));

        // Flip to received — stock appears now.
        $lines = $purchase->purchase_lines->map(fn ($l) => [
            'purchase_line_id' => $l->id,
            'variation_id' => $l->variation_id,
            'quantity' => $l->quantity,
            'purchase_price' => $l->purchase_price,
            'purchase_price_inc_tax' => $l->purchase_price_inc_tax,
        ])->all();

        $this->purchases->update(
            $purchase,
            ['status' => TransactionTypes::STATUS_RECEIVED],
            $lines
        );

        $this->assertSame(20.0, $this->stock->currentStock($variation->id, $this->location->id));
    }

    #[Test]
    public function the_full_cycle_keeps_stock_fifo_and_payment_status_consistent(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        // Two purchases at different costs.
        $this->purchases->create(
            ['location_id' => $this->location->id, 'contact_id' => $this->supplier->id],
            [['variation_id' => $variation->id, 'quantity' => 10, 'purchase_price' => 10, 'purchase_price_inc_tax' => 10]]
        );
        $this->purchases->create(
            ['location_id' => $this->location->id, 'contact_id' => $this->supplier->id],
            [['variation_id' => $variation->id, 'quantity' => 10, 'purchase_price' => 12, 'purchase_price_inc_tax' => 12]]
        );

        $this->assertSame(20.0, $this->stock->currentStock($variation->id, $this->location->id));

        // Sell 15, part-paid.
        $sale = $this->sells->create(
            [
                'location_id' => $this->location->id,
                'contact_id' => $this->customer->id,
                'status' => TransactionTypes::STATUS_FINAL,
            ],
            [[
                'variation_id' => $variation->id,
                'quantity' => 15,
                'unit_price' => 20,
                'unit_price_inc_tax' => 20,
            ]],
            [['amount' => 100, 'method' => 'cash']]
        );

        $this->assertSame(300.0, (float) $sale->final_total);
        $this->assertSame(TransactionTypes::PARTIAL, $sale->payment_status);
        $this->assertSame(100.0, $this->payments->amountPaid($sale));
        $this->assertSame(200.0, $this->payments->amountDue($sale));

        $this->assertSame(5.0, $this->stock->currentStock($variation->id, $this->location->id));
        $this->assertSame(0.0, $this->stock->reconcile($variation->id, $this->location->id)['difference']);

        // Settle the rest — status flips to paid.
        DB::transaction(fn () => $this->payments->addPayment($sale, [
            'amount' => 200, 'method' => 'bank_transfer',
        ]));

        $this->assertSame(TransactionTypes::PAID, $sale->fresh()->payment_status);

        // Return 4 units.
        $sellLine = $sale->sell_lines()->first();

        $return = $this->sells->addReturn($sale, [
            ['sell_line_id' => $sellLine->id, 'quantity' => 4],
        ]);

        $this->assertSame(TransactionTypes::SELL_RETURN, $return->type);
        $this->assertSame(80.0, (float) $return->final_total);
        $this->assertSame(4.0, (float) $sellLine->fresh()->quantity_returned);

        $this->assertSame(9.0, $this->stock->currentStock($variation->id, $this->location->id));
        $this->assertSame(0.0, $this->stock->reconcile($variation->id, $this->location->id)['difference']);
    }

    #[Test]
    public function a_purchase_return_reduces_stock_and_is_capped_at_what_remains(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $purchase = $this->purchases->create(
            ['location_id' => $this->location->id, 'contact_id' => $this->supplier->id],
            [['variation_id' => $variation->id, 'quantity' => 10, 'purchase_price' => 10, 'purchase_price_inc_tax' => 10]]
        );

        // Sell 8, leaving 2 returnable.
        $this->sells->create(
            [
                'location_id' => $this->location->id,
                'contact_id' => $this->customer->id,
                'status' => TransactionTypes::STATUS_FINAL,
            ],
            [['variation_id' => $variation->id, 'quantity' => 8, 'unit_price' => 20, 'unit_price_inc_tax' => 20]]
        );

        $lot = $purchase->purchase_lines->first();

        // Returning 5 must fail — only 2 of that lot are still on hand.
        try {
            $this->purchases->addReturn($purchase, [
                ['purchase_line_id' => $lot->id, 'quantity' => 5],
            ]);
            $this->fail('Expected the over-return to be rejected.');
        } catch (\RuntimeException) {
            // expected
        }

        $return = $this->purchases->addReturn($purchase, [
            ['purchase_line_id' => $lot->id, 'quantity' => 2],
        ]);

        $this->assertSame(20.0, (float) $return->final_total);
        $this->assertSame(0.0, $this->stock->currentStock($variation->id, $this->location->id));
        $this->assertSame(0.0, $this->stock->reconcile($variation->id, $this->location->id)['difference']);
    }

    #[Test]
    public function a_purchase_order_does_not_move_stock_and_tracks_fulfilment(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $order = $this->purchases->create(
            ['location_id' => $this->location->id, 'contact_id' => $this->supplier->id],
            [['variation_id' => $variation->id, 'quantity' => 100, 'purchase_price' => 10, 'purchase_price_inc_tax' => 10]],
            [],
            TransactionTypes::PURCHASE_ORDER
        );

        $this->assertSame(TransactionTypes::STATUS_ORDERED, $order->status);
        $this->assertSame(0.0, $this->stock->currentStock($variation->id, $this->location->id));

        $orderLine = $order->purchase_lines->first();

        // Invoice 40 of the 100 ordered.
        $this->purchases->create(
            [
                'location_id' => $this->location->id,
                'contact_id' => $this->supplier->id,
                'purchase_order_ids' => [$order->id],
            ],
            [[
                'variation_id' => $variation->id,
                'quantity' => 40,
                'purchase_price' => 10,
                'purchase_price_inc_tax' => 10,
                'purchase_order_line_id' => $orderLine->id,
            ]]
        );

        $this->assertSame(TransactionTypes::STATUS_PARTIAL, $order->fresh()->status);
        $this->assertSame(40.0, (float) $orderLine->fresh()->po_quantity_purchased);
        $this->assertSame(40.0, $this->stock->currentStock($variation->id, $this->location->id));

        // Invoice the remaining 60 → completed.
        $this->purchases->create(
            [
                'location_id' => $this->location->id,
                'contact_id' => $this->supplier->id,
                'purchase_order_ids' => [$order->id],
            ],
            [[
                'variation_id' => $variation->id,
                'quantity' => 60,
                'purchase_price' => 10,
                'purchase_price_inc_tax' => 10,
                'purchase_order_line_id' => $orderLine->id,
            ]]
        );

        $this->assertSame(TransactionTypes::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame(100.0, $this->stock->currentStock($variation->id, $this->location->id));
    }

    #[Test]
    public function payment_terms_are_stored_and_rejected_when_over_one_hundred_percent(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $purchase = $this->purchases->create(
            [
                'location_id' => $this->location->id,
                'contact_id' => $this->supplier->id,
                'terms' => [
                    ['payment_term' => 30, 'due_date' => now()->addDays(30)->toDateString()],
                    ['payment_term' => 70, 'due_date' => now()->addDays(60)->toDateString()],
                ],
            ],
            [['variation_id' => $variation->id, 'quantity' => 10, 'purchase_price' => 10, 'purchase_price_inc_tax' => 10]]
        );

        $this->assertCount(2, $purchase->terms);
        // 30% of a 100 invoice
        $this->assertSame(30.0, $purchase->terms->first()->amount);

        $this->expectException(\RuntimeException::class);

        $this->purchases->create(
            [
                'location_id' => $this->location->id,
                'contact_id' => $this->supplier->id,
                'terms' => [
                    ['payment_term' => 60, 'due_date' => now()->addDays(30)->toDateString()],
                    ['payment_term' => 60, 'due_date' => now()->addDays(60)->toDateString()],
                ],
            ],
            [['variation_id' => $variation->id, 'quantity' => 1, 'purchase_price' => 10, 'purchase_price_inc_tax' => 10]]
        );
    }

    #[Test]
    public function settling_a_contact_due_allocates_oldest_first_and_banks_the_excess(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->purchases->create(
            ['location_id' => $this->location->id, 'contact_id' => $this->supplier->id],
            [['variation_id' => $variation->id, 'quantity' => 100, 'purchase_price' => 1, 'purchase_price_inc_tax' => 1]]
        );

        // Two unpaid sales of 100 and 50.
        $first = $this->sells->create(
            ['location_id' => $this->location->id, 'contact_id' => $this->customer->id,
                'status' => TransactionTypes::STATUS_FINAL, 'transaction_date' => now()->subDays(2)],
            [['variation_id' => $variation->id, 'quantity' => 10, 'unit_price' => 10, 'unit_price_inc_tax' => 10]]
        );
        $second = $this->sells->create(
            ['location_id' => $this->location->id, 'contact_id' => $this->customer->id,
                'status' => TransactionTypes::STATUS_FINAL, 'transaction_date' => now()->subDay()],
            [['variation_id' => $variation->id, 'quantity' => 5, 'unit_price' => 10, 'unit_price_inc_tax' => 10]]
        );

        // Pay 170: covers 100 + 50, leaving 20 as advance balance.
        $result = DB::transaction(fn () => $this->payments->payContactDue($this->customer, [
            'amount' => 170,
            'method' => 'cash',
            'due_type' => 'sell',
        ]));

        $this->assertCount(2, $result['children']);
        $this->assertSame(20.0, $result['unallocated']);

        $this->assertSame(TransactionTypes::PAID, $first->fresh()->payment_status);
        $this->assertSame(TransactionTypes::PAID, $second->fresh()->payment_status);

        // Excess banked on the contact.
        $this->assertSame(20.0, (float) $this->customer->fresh()->balance);

        // Children hang off one parent payment.
        $this->assertSame(2, $result['parent']->child_payments()->count());
    }

    #[Test]
    public function a_credit_limit_breach_is_reported(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->purchases->create(
            ['location_id' => $this->location->id, 'contact_id' => $this->supplier->id],
            [['variation_id' => $variation->id, 'quantity' => 500, 'purchase_price' => 1, 'purchase_price_inc_tax' => 1]]
        );

        $this->sells->create(
            ['location_id' => $this->location->id, 'contact_id' => $this->customer->id,
                'status' => TransactionTypes::STATUS_FINAL],
            [['variation_id' => $variation->id, 'quantity' => 90, 'unit_price' => 10, 'unit_price_inc_tax' => 10]]
        );

        // Limit is 1000; 900 outstanding + a 300 sale = 1200 → over by 200.
        $over = $this->sells->creditLimitExceededBy($this->customer->fresh(), 300);

        $this->assertSame(200.0, $over);

        // Paying 300 up front keeps it inside the limit.
        $this->assertSame(
            0.0,
            $this->sells->creditLimitExceededBy($this->customer->fresh(), 300, 300)
        );
    }

    #[Test]
    public function stock_shortfalls_are_detected_before_a_sale_is_committed(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->purchases->create(
            ['location_id' => $this->location->id, 'contact_id' => $this->supplier->id],
            [['variation_id' => $variation->id, 'quantity' => 3, 'purchase_price' => 10, 'purchase_price_inc_tax' => 10]]
        );

        $shortfalls = $this->sells->findStockShortfalls($this->location->id, [
            ['variation_id' => $variation->id, 'quantity' => 5],
        ]);

        $this->assertCount(1, $shortfalls);
        $this->assertSame(5.0, $shortfalls[0]['requested']);
        $this->assertSame(3.0, $shortfalls[0]['available']);

        $this->assertEmpty($this->sells->findStockShortfalls($this->location->id, [
            ['variation_id' => $variation->id, 'quantity' => 3],
        ]));
    }

    #[Test]
    public function deleting_a_sale_returns_its_stock(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->purchases->create(
            ['location_id' => $this->location->id, 'contact_id' => $this->supplier->id],
            [['variation_id' => $variation->id, 'quantity' => 10, 'purchase_price' => 10, 'purchase_price_inc_tax' => 10]]
        );

        $sale = $this->sells->create(
            ['location_id' => $this->location->id, 'contact_id' => $this->customer->id,
                'status' => TransactionTypes::STATUS_FINAL],
            [['variation_id' => $variation->id, 'quantity' => 6, 'unit_price' => 20, 'unit_price_inc_tax' => 20]],
            [['amount' => 120, 'method' => 'cash']]
        );

        $this->assertSame(4.0, $this->stock->currentStock($variation->id, $this->location->id));

        $this->sells->delete($sale);

        $this->assertSame(10.0, $this->stock->currentStock($variation->id, $this->location->id));
        $this->assertSame(0.0, $this->stock->reconcile($variation->id, $this->location->id)['difference']);
        $this->assertNull(Transaction::find($sale->id));
    }
}
