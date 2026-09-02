<?php

namespace Tests\Feature\Finance;

use App\Models\Contact;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Services\CashRegisterService;
use App\Services\PaymentService;
use App\Support\TransactionTypes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Money handed over above what the sale is worth.
 *
 * Three outcomes, and the point of the feature is that the terminal picks between
 * them without the cashier having to know the accounting:
 *
 *   1. the customer owes money  → the excess comes off the debt, oldest document
 *                                 first, and any remainder becomes credit
 *   2. they owe nothing, cash   → nothing is recorded beyond the sale
 *   3. they owe nothing, credit → the excess is stored on their balance
 *
 * What these tests exist to pin down is the invariant that is easiest to break
 * while implementing any of them: **the excess never lands on the sale itself**.
 * It is settled against the contact, so `amount_paid` on the invoice must equal
 * `final_total` exactly — an overpaid document would derive its `payment_status`
 * from a figure that overstates what was ever owed on it, and would show a
 * customer as having paid 500 against a 320 invoice.
 *
 * The drawer is checked alongside, because it is the other thing that can be
 * quietly wrong: the cashier holds the full tender in every case, so `cash_in_hand`
 * must account for all of it — including case 1, where the money is split across
 * an invoice and a debt through a parent row and a child row, and case 2, where
 * 180 of it is about to be handed straight back.
 */
class OverpaymentTest extends TestCase
{
    use DatabaseTransactions;

    private PaymentService $payments;

    private CashRegisterService $registers;

    private Contact $customer;

    private Contact $walkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = app(PaymentService::class);
        $this->registers = app(CashRegisterService::class);

        $this->createTenant();

        /*
         * These tests ring sales up over HTTP, and `Controller::permit()` clears
         * only for an admin — "Admin" being a tenant-namespaced ROLE, which
         * `createTenant()` does not seed. Without it every post here would be a
         * 403 and every assertion would pass or fail for the wrong reason.
         *
         * Created directly rather than through BusinessService::createDefaultRoles(),
         * which also builds a Cashier role and syncs the permission catalogue onto
         * it — none of which is needed to authorise the owner, and all of which
         * would need the catalogue seeded first. Admin deliberately carries no
         * explicit permissions: `User::isAdmin()` short-circuits every check.
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

    /**
     * An unpaid invoice, which is what gives the customer a debt to settle.
     */
    private function unpaidSale(float $total, ?string $date = null): Transaction
    {
        return Transaction::create([
            'business_id' => $this->business->id,
            'location_id' => $this->location->id,
            'type' => TransactionTypes::SELL,
            'status' => TransactionTypes::STATUS_FINAL,
            'payment_status' => TransactionTypes::DUE,
            'transaction_date' => $date ?? now()->subMonth(),
            'contact_id' => $this->customer->id,
            'total_before_tax' => $total,
            'final_total' => $total,
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * Ring up a sale at the terminal, tendering `$tendered` for it.
     *
     * Goes through the HTTP endpoint rather than the service, because the split
     * between what belongs to the sale and what is excess is the controller's
     * decision and is the thing under test. The payment amount is capped exactly
     * as the terminal's script caps it.
     */
    private function ringUp(
        float $price,
        float $tendered,
        ?int $contactId = null,
        string $action = 'credit'
    ): \Illuminate\Testing\TestResponse {
        $product = $this->createProduct();

        return $this->post(route('pos.store'), [
            'location_id' => $this->location->id,
            'contact_id' => $contactId ?? $this->customer->id,
            'lines' => [
                [
                    'variation_id' => $this->variationOf($product)->id,
                    'quantity' => 1,
                    'unit_price' => $price,
                ],
            ],
            'payments' => [
                ['amount' => min($tendered, $price), 'method' => 'cash'],
            ],
            'overpay_amount' => max(0, $tendered - $price),
            'overpay_action' => $action,
        ]);
    }

    /**
     * The sale the terminal just rang up.
     *
     * Highest id rather than latest date: the fixtures deliberately backdate the
     * debts, and one of them is dated today.
     */
    private function posSale(): Transaction
    {
        return Transaction::where('type', TransactionTypes::SELL)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function balanceOf(Contact $contact): float
    {
        return round((float) $contact->fresh()->balance, 4);
    }

    /* ================================================================
     | The invariant: the excess never touches the sale
     ================================================================ */

    #[Test]
    public function the_sale_is_paid_exactly_and_never_overpaid(): void
    {
        $this->ringUp(price: 320, tendered: 500);

        $sale = $this->posSale();

        $this->assertSame(320.0, round((float) $sale->final_total, 4));
        $this->assertSame(
            320.0,
            $this->payments->amountPaid($sale),
            'The excess was recorded against the sale, which overstates what was owed on it.'
        );
        $this->assertSame(TransactionTypes::PAID, $sale->payment_status);
    }

    /* ================================================================
     | 1. The customer owes money
     ================================================================ */

    #[Test]
    public function the_excess_comes_off_an_existing_debt(): void
    {
        $debt = $this->unpaidSale(250);

        $this->ringUp(price: 320, tendered: 500);

        // 180 of excess against 250 of debt: the debt is partly settled and there
        // is nothing left to become credit.
        $this->assertSame(70.0, $this->payments->amountDue($debt->fresh()));
        $this->assertSame(TransactionTypes::PARTIAL, $debt->fresh()->payment_status);
        $this->assertSame(0.0, $this->balanceOf($this->customer));
    }

    #[Test]
    public function the_excess_clears_the_oldest_debt_first_then_becomes_credit(): void
    {
        $older = $this->unpaidSale(100, now()->subMonths(3)->toDateTimeString());
        $newer = $this->unpaidSale(60, now()->subMonths(2)->toDateTimeString());

        $this->ringUp(price: 200, tendered: 400);

        // 200 of excess: 100 clears the older, 60 clears the newer, 40 is left.
        $this->assertSame(TransactionTypes::PAID, $older->fresh()->payment_status);
        $this->assertSame(TransactionTypes::PAID, $newer->fresh()->payment_status);
        $this->assertSame(40.0, $this->balanceOf($this->customer));
    }

    /* ================================================================
     | 2. and 3. No debt
     ================================================================ */

    #[Test]
    public function keeping_the_excess_stores_it_as_credit(): void
    {
        $this->ringUp(price: 320, tendered: 500, action: 'credit');

        $this->assertSame(180.0, $this->balanceOf($this->customer));
    }

    #[Test]
    public function refunding_the_excess_records_nothing_beyond_the_sale(): void
    {
        $this->ringUp(price: 320, tendered: 500, action: 'refund');

        $this->assertSame(0.0, $this->balanceOf($this->customer));

        // One payment row, for the sale, and no contact settlement beside it.
        $this->assertSame(1, TransactionPayment::where('payment_for', $this->customer->id)->count());
        $this->assertSame(320.0, $this->payments->amountPaid($this->posSale()));
    }

    /* ================================================================
     | The walk-in customer
     ================================================================ */

    #[Test]
    public function credit_is_refused_on_the_shared_walk_in_customer(): void
    {
        // The terminal does not offer the option. This asserts the server refuses
        // it as well, because a balance on the row every counter sale shares
        // belongs to nobody and the next cashier could spend it on anyone.
        $this->ringUp(price: 320, tendered: 500, contactId: $this->walkIn->id, action: 'credit');

        $this->assertSame(0.0, $this->balanceOf($this->walkIn));
        $this->assertSame(320.0, $this->payments->amountPaid($this->posSale()));
    }

    /* ================================================================
     | A payload that contradicts itself
     ================================================================ */

    #[Test]
    public function an_excess_claimed_on_a_partly_paid_sale_is_ignored(): void
    {
        $product = $this->createProduct();

        // 200 tendered against a 320 sale, with an excess claimed anyway. Both
        // cannot be true, and acting on the claim would mint 150 of credit out of
        // an invoice that is still 120 short.
        $this->post(route('pos.store'), [
            'location_id' => $this->location->id,
            'contact_id' => $this->customer->id,
            'lines' => [
                [
                    'variation_id' => $this->variationOf($product)->id,
                    'quantity' => 1,
                    'unit_price' => 320,
                ],
            ],
            'payments' => [['amount' => 200, 'method' => 'cash']],
            'overpay_amount' => 150,
            'overpay_action' => 'credit',
        ]);

        $this->assertSame(TransactionTypes::PARTIAL, $this->posSale()->payment_status);
        $this->assertSame(0.0, $this->balanceOf($this->customer));
    }

    /* ================================================================
     | The drawer accounts for the whole tender
     ================================================================ */

    #[Test]
    public function the_drawer_holds_the_full_tender_when_the_excess_pays_a_debt(): void
    {
        $register = $this->registers->open([
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
            'opening_amount' => 0,
        ]);

        $this->unpaidSale(250);
        $this->ringUp(price: 320, tendered: 500);

        // The cashier took 500 and handed nothing back, so the drawer must say
        // 500 — not the 320 of the sale, and not 680 from counting the settlement
        // twice through its child row.
        $this->assertSame(
            500.0,
            round((float) $this->registers->summary($register)['cash_in_hand'], 4)
        );
    }

    #[Test]
    public function the_drawer_holds_only_the_sale_when_the_excess_is_refunded(): void
    {
        $register = $this->registers->open([
            'location_id' => $this->location->id,
            'user_id' => $this->user->id,
            'opening_amount' => 0,
        ]);

        $this->ringUp(price: 320, tendered: 500, action: 'refund');

        // 500 in and 180 straight back out: what stays is the sale.
        $this->assertSame(
            320.0,
            round((float) $this->registers->summary($register)['cash_in_hand'], 4)
        );
    }

    /* ================================================================
     | contactDue(): one side of the ledger, not a net figure
     ================================================================ */

    #[Test]
    public function contact_due_reports_one_side_and_does_not_net_the_other(): void
    {
        $both = Contact::create([
            'business_id' => $this->business->id,
            'type' => 'both',
            'name' => 'Delta Supplies',
            'created_by' => $this->user->id,
        ]);

        foreach ([[TransactionTypes::SELL, 400], [TransactionTypes::PURCHASE, 300]] as [$type, $total]) {
            Transaction::create([
                'business_id' => $this->business->id,
                'location_id' => $this->location->id,
                'type' => $type,
                'status' => TransactionTypes::STATUS_FINAL,
                'payment_status' => TransactionTypes::DUE,
                'transaction_date' => now(),
                'contact_id' => $both->id,
                'total_before_tax' => $total,
                'final_total' => $total,
                'created_by' => $this->user->id,
            ]);
        }

        /* Netted, these would be 100 — and a settlement dialog seeded with 100
           would leave 300 of the customer invoice standing while looking to the
           clerk like it had cleared the account. */
        $this->assertSame(400.0, $this->payments->contactDue($both, 'sell'));
        $this->assertSame(300.0, $this->payments->contactDue($both, 'purchase'));
    }
}
