<?php

namespace Tests\Feature\Finance;

use App\Models\CashRegisterTransaction;
use App\Models\Contact;
use App\Models\Transaction;
use App\Services\CashRegisterService;
use App\Services\PaymentService;
use App\Support\TransactionTypes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a shift's drawer knows, and in which direction.
 *
 * The invariant under test is the one a cashier is judged by: `cash_in_hand` must
 * equal the notes that should physically be in the drawer. Every payment taken or
 * handed over at the till moves that figure, and a payment the drawer never hears
 * about makes the cashier answer for it at close.
 *
 * That is the bug NOTES.md §12.1 recorded and this file guards: cash paid to a
 * supplier or spent on an expense used to write no drawer row at all, because
 * `transaction_type` had no term for it. Half of these tests are about the term
 * (`payout`), the other half about the four movements that already worked and
 * must keep working.
 */
class CashDrawerTest extends TestCase
{
    use DatabaseTransactions;

    private CashRegisterService $registers;

    private PaymentService $payments;

    private Contact $supplier;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registers = app(CashRegisterService::class);
        $this->payments = app(PaymentService::class);

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
        ]);
    }

    /* ================================================================
     | Helpers
     ================================================================ */

    /**
     * Open the shift with a float, which is what makes a variance meaningful.
     */
    private function openRegister(float $float = 500): \App\Models\CashRegister
    {
        return $this->registers->open([
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
            'opening_amount' => $float,
        ]);
    }

    /**
     * A bare document of any type, with no lines — the drawer reads the payment
     * and the document's `type`, and nothing else.
     */
    private function document(string $type, float $total, ?int $contactId = null): Transaction
    {
        return Transaction::create([
            'business_id' => $this->business->id,
            'location_id' => $this->location->id,
            'type' => $type,
            'status' => TransactionTypes::STATUS_FINAL,
            'payment_status' => TransactionTypes::DUE,
            'transaction_date' => now(),
            'contact_id' => $contactId,
            'total_before_tax' => $total,
            'final_total' => $total,
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pay(Transaction $document, float $amount, array $data = []): \App\Models\TransactionPayment
    {
        return DB::transaction(fn () => $this->payments->addPayment($document, array_merge([
            'amount' => $amount,
            'method' => 'cash',
        ], $data)));
    }

    /**
     * @return array<int, CashRegisterTransaction>
     */
    private function drawerRows(\App\Models\CashRegister $register): array
    {
        return CashRegisterTransaction::where('cash_register_id', $register->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /* ================================================================
     | The gap §12.1 named
     ================================================================ */

    #[Test]
    public function cash_paid_to_a_supplier_leaves_the_drawer_as_a_payout(): void
    {
        $register = $this->openRegister(500);

        $this->pay($this->document(TransactionTypes::SELL, 200, $this->customer->id), 200);
        $this->pay($this->document(TransactionTypes::PURCHASE, 120, $this->supplier->id), 120);

        $summary = $this->registers->summary($register);

        // 500 float + 200 taken − 120 handed over. Before §12.1 this read 700.
        $this->assertSame(580.0, $summary['cash_in_hand']);
        $this->assertSame(120.0, $summary['payouts']);

        $payout = collect($this->drawerRows($register))
            ->firstWhere('transaction_type', 'payout');

        $this->assertNotNull($payout, 'the supplier payment wrote no drawer row');
        $this->assertSame('debit', $payout->type);
        $this->assertSame(120.0, (float) $payout->amount);
    }

    #[Test]
    public function an_expense_settled_at_the_till_is_a_payout_too(): void
    {
        $register = $this->openRegister(300);

        $this->pay($this->document(TransactionTypes::EXPENSE, 75), 75);

        $summary = $this->registers->summary($register);

        $this->assertSame(225.0, $summary['cash_in_hand']);
        $this->assertSame(75.0, $summary['payouts']);

        // Not takings, however the money moved: an expense is nobody's sale.
        $this->assertSame(0, $summary['sales_count']);
    }

    #[Test]
    public function a_supplier_refund_reverses_the_payout_instead_of_counting_as_takings(): void
    {
        $register = $this->openRegister(500);

        $this->pay($this->document(TransactionTypes::PURCHASE, 200, $this->supplier->id), 200);
        $this->pay($this->document(TransactionTypes::PURCHASE_RETURN, 80, $this->supplier->id), 80);

        $summary = $this->registers->summary($register);

        // 500 − 200 + 80. The refund is money in, but it is not a sale.
        $this->assertSame(380.0, $summary['cash_in_hand']);
        $this->assertSame(120.0, $summary['payouts'], 'payouts should be net of what came back');
        $this->assertSame(0, $summary['sales_count']);

        $refund = collect($this->drawerRows($register))
            ->last(fn ($row) => $row->transaction_type === 'payout');

        // Filed under the movement it reverses, with direction on `type`.
        $this->assertSame('credit', $refund->type);
    }

    #[Test]
    public function settling_a_supplier_balance_at_the_till_is_a_payout(): void
    {
        $register = $this->openRegister(500);

        $purchase = $this->document(TransactionTypes::PURCHASE, 90, $this->supplier->id);

        DB::transaction(fn () => $this->payments->payContactDue($this->supplier, [
            'amount' => 90,
            'method' => 'cash',
            'due_type' => 'purchase',
        ]));

        $summary = $this->registers->summary($register);

        $this->assertSame(410.0, $summary['cash_in_hand']);
        $this->assertSame(90.0, $summary['payouts']);
        $this->assertSame(TransactionTypes::PAID, $purchase->fresh()->payment_status);

        /*
         * One row, not two: the parent settlement is the movement and the child
         * allocation against the invoice is bookkeeping. Counting both would empty
         * the drawer twice over.
         */
        $this->assertCount(1, array_filter(
            $this->drawerRows($register),
            fn ($row) => $row->transaction_type === 'payout'
        ));
    }

    #[Test]
    public function correcting_a_supplier_payment_keeps_it_filed_as_a_payout(): void
    {
        $register = $this->openRegister(500);

        $payment = $this->pay($this->document(TransactionTypes::PURCHASE, 120, $this->supplier->id), 120);

        DB::transaction(fn () => $this->payments->updatePayment($payment, ['amount' => 150]));

        $summary = $this->registers->summary($register);

        $this->assertSame(350.0, $summary['cash_in_hand']);
        $this->assertSame(150.0, $summary['payouts']);

        $rows = $this->drawerRows($register);

        // Corrected in place. A second row would double the money out.
        $this->assertCount(2, $rows, 'the float row plus one payout row');
        $this->assertSame('payout', $rows[1]->transaction_type);
    }

    #[Test]
    public function a_payout_paid_by_card_never_touches_the_cash_figure(): void
    {
        $register = $this->openRegister(500);

        $this->pay($this->document(TransactionTypes::PURCHASE, 120, $this->supplier->id), 120, [
            'method' => 'card',
        ]);

        $summary = $this->registers->summary($register);

        // Stated as paid out, because it was — but no notes left the drawer.
        $this->assertSame(500.0, $summary['cash_in_hand']);
        $this->assertSame(120.0, $summary['payouts']);
        $this->assertSame(-120.0, $summary['by_method']['card']);
    }

    /* ================================================================
     | The four movements that already worked
     ================================================================ */

    #[Test]
    public function a_sale_is_still_a_sale_and_a_return_is_still_a_refund(): void
    {
        $register = $this->openRegister(100);

        $this->pay($this->document(TransactionTypes::SELL, 250, $this->customer->id), 250);
        $this->pay($this->document(TransactionTypes::SELL_RETURN, 40, $this->customer->id), 40);

        $summary = $this->registers->summary($register);

        $this->assertSame(310.0, $summary['cash_in_hand']);
        $this->assertSame(40.0, $summary['refunds']);
        $this->assertSame(0.0, $summary['payouts'], 'a sell return is not a payout');
        $this->assertSame(1, $summary['sales_count']);

        $kinds = array_map(fn ($row) => $row->transaction_type, $this->drawerRows($register));

        $this->assertSame(['initial', 'sell', 'refund'], $kinds);
    }

    #[Test]
    public function change_handed_back_on_a_sale_still_reverses_the_direction(): void
    {
        $register = $this->openRegister(100);

        $sale = $this->document(TransactionTypes::SELL, 250, $this->customer->id);

        $this->pay($sale, 250);
        $this->pay($sale, 10, ['is_return' => 1]);

        $summary = $this->registers->summary($register);

        $this->assertSame(340.0, $summary['cash_in_hand']);
        $this->assertSame(0.0, $summary['payouts']);
    }

    #[Test]
    public function an_advance_payment_still_stays_out_of_the_drawer(): void
    {
        $register = $this->openRegister(500);

        DB::transaction(function () {
            $this->payments->addAdvanceBalance($this->supplier, 200);

            $purchase = $this->document(TransactionTypes::PURCHASE, 200, $this->supplier->id);

            $this->payments->useAdvanceBalance($this->supplier, $purchase, 200);
        });

        $summary = $this->registers->summary($register);

        // The supplier's balance paid for it; no notes moved.
        $this->assertSame(500.0, $summary['cash_in_hand']);
        $this->assertSame(0.0, $summary['payouts']);
        $this->assertCount(1, $this->drawerRows($register));
    }

    #[Test]
    public function a_payout_taken_with_no_register_open_writes_nothing(): void
    {
        $payment = $this->pay($this->document(TransactionTypes::PURCHASE, 120, $this->supplier->id), 120);

        $this->assertSame(0, CashRegisterTransaction::where('transaction_payment_id', $payment->id)->count());

        /*
         * And opening a register afterwards does not retroactively acquire it. A
         * back-office payment is a real payment that simply did not pass through
         * anyone's till, and charging it to the next cashier's shift would make
         * them short by it.
         */
        $register = $this->openRegister(500);

        $this->assertSame(0.0, $this->registers->summary($register)['payouts']);
        $this->assertSame(500.0, $this->registers->summary($register)['cash_in_hand']);
    }

    #[Test]
    public function deleting_a_supplier_payment_removes_its_payout_row(): void
    {
        $register = $this->openRegister(500);

        $payment = $this->pay($this->document(TransactionTypes::PURCHASE, 120, $this->supplier->id), 120);

        DB::transaction(fn () => $this->payments->deletePayment($payment));

        $summary = $this->registers->summary($register);

        $this->assertSame(500.0, $summary['cash_in_hand']);
        $this->assertSame(0.0, $summary['payouts']);
    }
}
