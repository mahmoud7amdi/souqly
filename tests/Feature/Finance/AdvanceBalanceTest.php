<?php

namespace Tests\Feature\Finance;

use App\Models\Contact;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Services\PaymentService;
use App\Support\TransactionTypes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Credit the customer left earlier, spent on goods they take without paying.
 *
 * The mirror of {@see OverpaymentTest}: there the tender was more than the sale
 * and the excess had to go somewhere, here it is less — or absent entirely — and
 * stored credit makes up the difference. Both files are the same requirement seen
 * from its two sides, which is why they are siblings.
 *
 * The rule, stated the way a shopkeeper would state it without using the word
 * accounting: money already handed over pays for what is taken, and anything the
 * credit does not reach stays owed. So the two figures under test in nearly every
 * case below are the contact's balance afterwards and the document's remaining
 * due.
 *
 * What is deliberately *not* asserted is the split between "covered" and "still
 * due" as a number the service reports about itself. There is no second
 * implementation of that arithmetic to check: the service files a payment row for
 * as far as the balance reaches and `refreshPaymentStatus()` derives the status
 * from it. So these tests read the derived status and the surviving balance — the
 * two things that would actually be wrong if the split were wrong.
 */
class AdvanceBalanceTest extends TestCase
{
    use DatabaseTransactions;

    private PaymentService $payments;

    private Contact $customer;

    private Contact $walkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = app(PaymentService::class);

        $this->createTenant();

        /*
         * Same reason as OverpaymentTest::setUp(): the cases below post to the
         * terminal, `Controller::permit()` clears only for an admin, and "Admin"
         * is a tenant-namespaced role that `createTenant()` does not seed. Without
         * it every post would 403 and every assertion would fail for the wrong
         * reason.
         */
        $admin = Role::create([
            'name' => Role::nameFor('Admin', $this->business->id),
            'business_id' => $this->business->id,
            'is_default' => true,
            'guard_name' => 'web',
        ]);

        $this->user->assignRole($admin);

        // Seeded inside this test's transaction, so anything spatie cached during
        // an earlier test points at ids that no longer exist.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->user);

        $this->customer = Contact::create([
            'business_id' => $this->business->id,
            'type' => 'customer',
            'name' => 'Ahmed Hassan',
            'created_by' => $this->user->id,
        ]);

        $this->walkIn = Contact::create([
            'business_id' => $this->business->id,
            'type' => 'customer',
            'name' => 'Walk-In Customer',
            'is_default' => 1,
            'created_by' => $this->user->id,
        ]);
    }

    /* ================================================================
     | Helpers
     ================================================================ */

    /** Put credit on a contact, the way a prepayment leaves it there. */
    private function giveCredit(Contact $contact, float $amount): void
    {
        $this->payments->addAdvanceBalance($contact, $amount);
        $contact->refresh();
    }

    /**
     * A document that is genuinely owed, which is the only thing credit may be
     * spent against.
     */
    private function owedDocument(
        float $total,
        ?Contact $contact = null,
        string $type = TransactionTypes::SELL,
        string $status = TransactionTypes::STATUS_FINAL
    ): Transaction {
        return Transaction::create([
            'business_id' => $this->business->id,
            'location_id' => $this->location->id,
            'type' => $type,
            'status' => $status,
            'payment_status' => TransactionTypes::DUE,
            'transaction_date' => now(),
            'contact_id' => ($contact ?? $this->customer)->id,
            'total_before_tax' => $total,
            'final_total' => $total,
            'created_by' => $this->user->id,
        ]);
    }

    private function balanceOf(Contact $contact): float
    {
        return round((float) $contact->fresh()->balance, 4);
    }

    /**
     * Ring a sale up at the terminal, tendering `$tendered` in cash for it.
     *
     * Over HTTP rather than through the service, because the requirement is about
     * what the terminal does: a sale left unpaid having the customer's credit
     * spent on it, without the cashier being asked, is the controller's behaviour.
     */
    private function ringUp(float $price, float $tendered = 0): \Illuminate\Testing\TestResponse
    {
        $product = $this->createProduct();

        $payload = [
            'location_id' => $this->location->id,
            'contact_id' => $this->customer->id,
            'lines' => [
                [
                    'variation_id' => $this->variationOf($product)->id,
                    'quantity' => 1,
                    'unit_price' => $price,
                ],
            ],
        ];

        /*
         * A sale taken entirely on credit sends no payment at all — which is what
         * `nullable` on the payments array is for, and not a zero-amount row that
         * `addPayment()` would reject.
         */
        if ($tendered > 0) {
            $payload['payments'] = [['amount' => min($tendered, $price), 'method' => 'cash']];
        }

        return $this->post(route('pos.store'), $payload);
    }

    private function posSale(): Transaction
    {
        return Transaction::where('type', TransactionTypes::SELL)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /* ================================================================
     | The requirement: credit pays, and the remainder stays owed
     ================================================================ */

    #[Test]
    public function credit_smaller_than_the_sale_pays_what_it_can_and_the_rest_stays_due(): void
    {
        $this->giveCredit($this->customer, 200);

        $sale = $this->owedDocument(320);

        $this->payments->applyAdvanceBalance($sale);

        /*
         * The half of the requirement easiest to get wrong: the shortfall has to
         * survive as a debt on the document. Swallowing it would settle an invoice
         * with money that was never handed over.
         */
        $this->assertSame(120.0, $this->payments->amountDue($sale->fresh()));
        $this->assertSame(TransactionTypes::PARTIAL, $sale->fresh()->payment_status);
        $this->assertSame(
            0.0,
            $this->balanceOf($this->customer),
            'The credit should have been spent down to nothing.'
        );
    }

    #[Test]
    public function credit_larger_than_the_sale_settles_it_and_keeps_the_remainder(): void
    {
        $this->giveCredit($this->customer, 500);

        $sale = $this->owedDocument(320);

        $this->payments->applyAdvanceBalance($sale);

        $this->assertSame(0.0, $this->payments->amountDue($sale->fresh()));
        $this->assertSame(TransactionTypes::PAID, $sale->fresh()->payment_status);
        $this->assertSame(180.0, $this->balanceOf($this->customer));
    }

    #[Test]
    public function credit_that_exactly_covers_the_sale_leaves_neither_a_debt_nor_a_balance(): void
    {
        $this->giveCredit($this->customer, 320);

        $sale = $this->owedDocument(320);

        $this->payments->applyAdvanceBalance($sale);

        $this->assertSame(TransactionTypes::PAID, $sale->fresh()->payment_status);
        $this->assertSame(0.0, $this->balanceOf($this->customer));
    }

    #[Test]
    public function the_spend_is_filed_as_an_advance_payment_against_the_document(): void
    {
        $this->giveCredit($this->customer, 200);

        $sale = $this->owedDocument(320);

        $this->payments->applyAdvanceBalance($sale);

        $payment = TransactionPayment::where('transaction_id', $sale->id)->sole();

        /*
         * The method is what every mirror of a payment keys off to decide that no
         * cash moved: the drawer, the account transactions and the contact ledger
         * all skip `advance`. Filed under any other method the spend would be
         * counted as takings that never arrived.
         */
        $this->assertSame('advance', $payment->method);
        $this->assertSame(200.0, round((float) $payment->amount, 4));
    }

    /* ================================================================
     | When nothing should happen
     ================================================================ */

    #[Test]
    public function a_contact_with_no_credit_has_nothing_recorded(): void
    {
        $sale = $this->owedDocument(320);

        $this->assertNull($this->payments->applyAdvanceBalance($sale));
        $this->assertSame(TransactionTypes::DUE, $sale->fresh()->payment_status);
        $this->assertSame(0, TransactionPayment::where('transaction_id', $sale->id)->count());
    }

    #[Test]
    public function a_document_that_is_already_paid_does_not_touch_the_credit(): void
    {
        $this->giveCredit($this->customer, 500);

        $sale = $this->owedDocument(320);
        $this->payments->addPayment($sale, ['amount' => 320, 'method' => 'cash']);

        $this->assertNull($this->payments->applyAdvanceBalance($sale->fresh()));
        $this->assertSame(500.0, $this->balanceOf($this->customer));
    }

    #[Test]
    public function credit_sitting_on_the_shared_walk_in_row_is_never_spent(): void
    {
        /*
         * A balance on the row every counter sale is filed against belongs to
         * nobody in particular, so spending it would quietly hand one person's
         * prepayment to the next stranger through the door.
         */
        $this->giveCredit($this->walkIn, 500);

        $sale = $this->owedDocument(320, $this->walkIn);

        $this->assertNull($this->payments->applyAdvanceBalance($sale));
        $this->assertSame(500.0, $this->balanceOf($this->walkIn));
        $this->assertSame(TransactionTypes::DUE, $sale->fresh()->payment_status);
    }

    #[Test]
    public function a_draft_is_not_a_debt_and_does_not_consume_credit(): void
    {
        $this->giveCredit($this->customer, 500);

        $draft = $this->owedDocument(320, status: TransactionTypes::STATUS_DRAFT);

        $this->assertNull($this->payments->applyAdvanceBalance($draft));
        $this->assertSame(500.0, $this->balanceOf($this->customer));
    }

    #[Test]
    public function a_return_does_not_consume_credit(): void
    {
        $this->giveCredit($this->customer, 500);

        $return = $this->owedDocument(320, type: TransactionTypes::SELL_RETURN);

        $this->assertNull($this->payments->applyAdvanceBalance($return));
        $this->assertSame(500.0, $this->balanceOf($this->customer));
    }

    /* ================================================================
     | At the terminal
     ================================================================ */

    #[Test]
    public function a_pos_sale_taken_on_credit_is_paid_from_the_balance(): void
    {
        $this->giveCredit($this->customer, 500);

        // Nothing tendered: the customer takes the goods and hands over no money,
        // which is the case the requirement is written about.
        $this->ringUp(price: 320);

        $sale = $this->posSale();

        $this->assertSame(TransactionTypes::PAID, $sale->payment_status);
        $this->assertSame(180.0, $this->balanceOf($this->customer));
        $this->assertSame(
            'advance',
            TransactionPayment::where('transaction_id', $sale->id)->sole()->method
        );
    }

    #[Test]
    public function a_pos_sale_bigger_than_the_balance_leaves_the_remainder_owed(): void
    {
        $this->giveCredit($this->customer, 200);

        $this->ringUp(price: 320);

        $sale = $this->posSale();

        $this->assertSame(TransactionTypes::PARTIAL, $sale->payment_status);
        $this->assertSame(120.0, $this->payments->amountDue($sale));
        $this->assertSame(0.0, $this->balanceOf($this->customer));
    }

    #[Test]
    public function a_part_tendered_pos_sale_has_only_its_shortfall_taken_from_credit(): void
    {
        $this->giveCredit($this->customer, 500);

        /*
         * 100 in cash against a 320 sale: the credit covers the 220 gap and no
         * more. Spending 320 of it would be charging the customer twice for the
         * 100 they just put on the counter.
         */
        $this->ringUp(price: 320, tendered: 100);

        $sale = $this->posSale();

        $this->assertSame(TransactionTypes::PAID, $sale->payment_status);
        $this->assertSame(320.0, $this->payments->amountPaid($sale));
        $this->assertSame(280.0, $this->balanceOf($this->customer));
    }

    #[Test]
    public function the_walk_in_customer_is_refused_at_the_terminal_too(): void
    {
        $this->giveCredit($this->walkIn, 500);

        $product = $this->createProduct();

        $this->post(route('pos.store'), [
            'location_id' => $this->location->id,
            'contact_id' => $this->walkIn->id,
            'lines' => [
                [
                    'variation_id' => $this->variationOf($product)->id,
                    'quantity' => 1,
                    'unit_price' => 320,
                ],
            ],
        ]);

        $this->assertSame(500.0, $this->balanceOf($this->walkIn));
        $this->assertSame(TransactionTypes::DUE, $this->posSale()->payment_status);
    }

    /* ================================================================
     | The spend must not be counted as money arriving
     ================================================================ */

    #[Test]
    public function the_ledger_does_not_credit_the_spend_a_second_time(): void
    {
        /*
         * The failure this guards, stated as its numbers: top up 500 and take 300
         * of goods on credit, and a statement that credited the spend as well as
         * the top-up would close at −500 beside an advance-balance stat saying 200
         * is left. The cash arrived once and may be reported once.
         *
         * Seeded through payContactDue() rather than addAdvanceBalance() because
         * only the real path writes the payment row the ledger reads — the bug
         * needs both rows present to be visible.
         */
        $this->payments->payContactDue($this->customer, [
            'amount' => 500,
            'method' => 'cash',
            'due_type' => 'sell',
            'created_by' => $this->user->id,
        ]);

        $sale = $this->owedDocument(300);
        $this->payments->applyAdvanceBalance($sale);

        $response = $this->get(route('contacts.ledger', $this->customer->id));

        $response->assertOk();

        $entries = collect($response->viewData('entries'));

        // The 500 that arrived and the 300 that was taken. The advance spend must
        // not appear as a third entry.
        $this->assertSame(500.0, round($entries->sum('credit'), 4));
        $this->assertSame(300.0, round($entries->sum('debit'), 4));

        // Which leaves the statement agreeing with the stat beside it: 200 left.
        $this->assertSame(-200.0, round((float) $entries->last()['balance'], 4));
    }

    /* ================================================================
     | The contact screen has to say where the credit went
     ================================================================ */

    #[Test]
    public function the_spend_appears_among_the_contacts_recent_movements(): void
    {
        $this->giveCredit($this->customer, 500);

        $sale = $this->owedDocument(300);
        $this->payments->applyAdvanceBalance($sale);

        $response = $this->get(route('contacts.show', $this->customer->id));

        $response->assertOk();

        $movements = collect($response->viewData('recentTransactions'));

        /*
         * Without the payment half of this list the screen shows a `paid` invoice
         * with no payment beside it, which reads as an invoice somebody forgot to
         * collect on. The method is what names the row as a spend against credit.
         *
         * Compared against the translated string rather than a literal because the
         * fixture tenant runs in Arabic — the assertion has to ask the same
         * question the view answered.
         */
        $this->assertTrue(
            $movements->contains(
                fn ($movement) => $movement['method'] === __('lang_v1.advance')
                    && round((float) $movement['total'], 4) === 300.0
            ),
            'The advance spend is missing from recent movements, so the screen cannot say where the credit went.'
        );
    }
}
