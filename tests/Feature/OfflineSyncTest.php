<?php

namespace Tests\Feature;

use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\InvoiceLayout;
use App\Models\InvoiceScheme;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use App\Models\Variation;
use App\Services\BusinessService;
use App\Services\PurchaseService;
use App\Support\Permissions;
use App\Support\Tenancy;
use App\Support\TransactionTypes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The offline terminal — item 10.
 *
 * WHAT THIS FILE IS FOR
 *
 * Everything the offline layer promises is a claim about money that was taken
 * while nobody could check it. A sale rung up on a till with no uplink exists in
 * exactly one place — a browser's IndexedDB on a shop counter — until this
 * endpoint accepts it. Two things can go wrong, and both reconcile to cash:
 *
 *   the sale never lands  → the shop is short and nobody knows why
 *   the sale lands twice  → the takings are overstated and the shelf is fuller
 *                           than the system says
 *
 * The second is the one that needs tests, because it is the one that *looks*
 * fine. A duplicate is a well-formed sale with a plausible total; nothing on any
 * screen says it should not be there. So the assertions below are mostly about
 * counting: after a replay, and after the *same* replay again, how many
 * transactions exist.
 *
 * WHY THE COUNTS ARE ASSERTED AND NOT JUST THE VERDICTS
 *
 * `duplicate` is a string in a JSON response, and a controller that answers
 * `duplicate` while also inserting a row would pass a verdict-only test
 * perfectly. Every de-duplication test here therefore asserts the row count too.
 *
 * {@see ScreensRenderTest} skips `offline.data` — its SKIP entry names this file
 * as where the snapshot is covered, so the snapshot's gate, row shape and query
 * count are asserted here.
 *
 * The POS block at the end covers what the terminal itself now sends. `POST /pos`
 * grew two fields this item, and one of them had a defect that only a two-sale
 * test can see: an unfilled hidden field posts `''`, and `''` is a value the
 * unique index accepts exactly once. Without
 * {@see \App\Http\Controllers\SellPosController::store()}'s empty-string guard,
 * the *second* counter sale of the day would be refused — on a screen that has
 * nothing to do with being offline.
 */
class OfflineSyncTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $businessId;

    /*
     * `$this->location` is deliberately NOT declared here. `Tests\TestCase:25`
     * already owns it as `protected ?BusinessLocation $location = null`, and
     * `createTenant()` is what assigns it (`TestCase:98`) — so redeclaring it
     * `private` narrows an inherited property's visibility, which PHP refuses
     * with a fatal error before a single test runs. `PrintingTest` sidesteps the
     * question by naming its own location `$branch`; this suite uses the
     * inherited one, which is why it has no declaration of its own.
     */

    private Variation $variation;

    private Contact $customer;

    private Contact $supplier;

    /**
     * Queries counted while it is an int, ignored while it is null.
     *
     * A single listener registered once in setUp, switched on and off by
     * {@see queriesFor()} — one listener per measurement would leave the earlier
     * ones running and counting into variables nobody reads.
     */
    private ?int $queryTally = null;

    protected function setUp(): void
    {
        parent::setUp();

        DB::listen(function () {
            if ($this->queryTally !== null) {
                $this->queryTally++;
            }
        });

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $currency = \App\Models\Currency::firstOrCreate(
            ['code' => 'EGP'],
            ['country' => 'Egypt', 'currency' => 'Egyptian Pound', 'symbol' => 'ج.م',
                'thousand_separator' => ',', 'decimal_separator' => '.']
        );

        /*
         * A registered business, not createTenant(): `permit()` waves an admin
         * through via `Gate::before()`, and `isAdmin()` is a *role* that only
         * BusinessService::register() creates. With a bare owner every request
         * below would 403 and the file would be asserting the gate, not the sync.
         */
        ['owner' => $owner, 'business' => $business] = app(BusinessService::class)->register(
            ['name' => 'Offline Co.', 'currency_id' => $currency->id],
            ['first_name' => 'Admin', 'username' => 'offline_'.uniqid(),
                'password' => 'secret-pass', 'language' => 'ar']
        );

        $this->admin = $owner;
        $this->businessId = $business->id;
        Tenancy::bind($business->id);

        $this->location = BusinessLocation::first();
        $this->seedFixtures();

        $this->actingAs($this->admin);
    }

    /* ================================================================
     | Fixtures
     ================================================================ */

    private function seedFixtures(): void
    {
        $unit = Unit::first() ?? Unit::create([
            'actual_name' => 'Pieces', 'short_name' => 'Pc',
            'allow_decimal' => 0, 'created_by' => $this->admin->id,
        ]);

        $this->variation = $this->makeProduct('Offline product', $unit->id);

        $this->supplier = Contact::create([
            'type' => 'supplier', 'name' => 'Offline supplier',
            'supplier_business_name' => 'Offline supplier',
            'contact_status' => 'active', 'created_by' => $this->admin->id,
        ]);

        $this->customer = Contact::create([
            'type' => 'customer', 'name' => 'Offline customer',
            'first_name' => 'Offline customer', 'contact_status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        // Real stock behind the sales, so `qty_available` in the snapshot is a
        // figure and not a zero that any join would produce.
        app(PurchaseService::class)->create(
            [
                'location_id' => $this->location->id,
                'contact_id' => $this->supplier->id,
                'status' => TransactionTypes::STATUS_RECEIVED,
                'transaction_date' => now()->subDay()->toDateTimeString(),
                'created_by' => $this->admin->id,
            ],
            [[
                'product_id' => $this->variation->product_id,
                'variation_id' => $this->variation->id,
                'quantity' => 100,
                'purchase_price' => 8,
                'purchase_price_inc_tax' => 8,
            ]]
        );
    }

    /**
     * One sellable, stock-tracked product with one variation.
     */
    private function makeProduct(string $name, int $unitId): Variation
    {
        $product = Product::create([
            'name' => $name, 'type' => 'single', 'unit_id' => $unitId,
            'tax_type' => 'exclusive', 'enable_stock' => 1, 'alert_quantity' => 0,
            'sku' => 'OFF-'.uniqid(), 'barcode_type' => 'C128',
            'created_by' => $this->admin->id,
        ]);

        $productVariation = ProductVariation::create([
            'product_id' => $product->id, 'name' => 'DUMMY', 'is_dummy' => 1,
        ]);

        return Variation::create([
            'product_id' => $product->id,
            'product_variation_id' => $productVariation->id,
            'name' => 'DUMMY', 'sub_sku' => $product->sku,
            'default_purchase_price' => 8, 'dpp_inc_tax' => 8,
            'profit_percent' => 25, 'default_sell_price' => 10,
            'sell_price_inc_tax' => 10,
        ]);
    }

    /**
     * One queued sale, shaped exactly as `forWire()` in resources/js/offline.js
     * sends it: the serialised form plus the three fields the device stamps on.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function queued(string $tempId, array $overrides = []): array
    {
        return array_merge([
            'temp_id' => $tempId,
            'device_id' => 'till-a',
            'created_at' => now()->toIso8601String(),
            'location_id' => $this->location->id,
            'contact_id' => $this->customer->id,
            'lines' => [[
                'variation_id' => $this->variation->id,
                'quantity' => 2,
                'unit_price' => 10,
            ]],
        ], $overrides);
    }

    /**
     * POST a batch and return the decoded body.
     *
     * @param  array<int, array<string, mixed>>  $sales
     * @return array<string, mixed>
     */
    private function replay(array $sales, string $deviceId = 'till-a'): array
    {
        $response = $this->postJson(route('offline.sync'), [
            'device_id' => $deviceId,
            'sales' => $sales,
        ]);

        $response->assertOk();

        return $response->json();
    }

    /**
     * The verdict for one temp id.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function verdict(array $body, string $tempId): array
    {
        foreach ($body['results'] ?? [] as $result) {
            if (($result['temp_id'] ?? null) === $tempId) {
                return $result;
            }
        }

        $this->fail('No verdict returned for temp id '.$tempId);
    }

    /** Sales only — the purchase in setUp() must not be counted. */
    private function sellCount(): int
    {
        return Transaction::query()->where('type', TransactionTypes::SELL)->count();
    }

    /* ================================================================
     | Replay — the happy path
     ================================================================ */

    #[Test]
    public function a_queued_sale_is_recorded_once_with_its_offline_provenance(): void
    {
        $before = $this->sellCount();

        $body = $this->replay([$this->queued('temp-basic')]);

        $verdict = $this->verdict($body, 'temp-basic');
        $this->assertSame('accepted', $verdict['status']);
        $this->assertNotEmpty($verdict['id']);
        $this->assertNotEmpty($verdict['invoice_no'], 'The till needs the real invoice number back.');

        $this->assertSame($before + 1, $this->sellCount());

        $sale = Transaction::query()->findOrFail($verdict['id']);

        $this->assertSame(TransactionTypes::SELL, $sale->type);
        $this->assertSame(TransactionTypes::STATUS_FINAL, $sale->status,
            'A POS sale is money in the drawer; there is no draft state for it to be in.');
        $this->assertSame('temp-basic', $sale->offline_temp_id);
        $this->assertSame('till-a', $sale->offline_device_id);
        $this->assertNotNull($sale->offline_created_at);
        $this->assertSame(20.0, round((float) $sale->final_total, 2), '2 x 10');

        // The lines went through the real service, so the FIFO map and the stock
        // cache moved with them — a replay is an ordinary sale, not an import.
        $this->assertSame(1, $sale->sell_lines()->count());
        $this->assertSame(
            98.0,
            round($this->variation->currentStock($this->location->id), 2),
            '100 bought, 2 sold offline.'
        );
    }

    #[Test]
    public function the_batch_reports_a_verdict_per_sale_and_a_sync_timestamp(): void
    {
        $body = $this->replay([
            $this->queued('temp-multi-1'),
            $this->queued('temp-multi-2'),
        ]);

        $this->assertArrayHasKey('synced_at', $body);
        $this->assertCount(2, $body['results']);
        $this->assertSame('accepted', $this->verdict($body, 'temp-multi-1')['status']);
        $this->assertSame('accepted', $this->verdict($body, 'temp-multi-2')['status']);
    }

    #[Test]
    public function payments_queued_with_the_sale_are_replayed_too(): void
    {
        $body = $this->replay([$this->queued('temp-paid', [
            'payments' => [['amount' => 20, 'method' => 'cash']],
        ])]);

        $sale = Transaction::query()->findOrFail($this->verdict($body, 'temp-paid')['id']);

        $this->assertSame(1, $sale->payment_lines()->count());
        $this->assertSame(TransactionTypes::PAID, $sale->payment_status,
            'A fully tendered counter sale must not sync as a debt.');
    }

    /* ================================================================
     | Exactly once — the whole reason this endpoint exists
     ================================================================ */

    #[Test]
    public function replaying_the_same_temp_id_answers_duplicate_and_creates_nothing(): void
    {
        $first = $this->replay([$this->queued('temp-once')]);
        $id = $this->verdict($first, 'temp-once')['id'];

        $after = $this->sellCount();

        // The failure this endpoint exists for: the server committed, the reply
        // died on the way back, the till cannot tell that from "never arrived",
        // and so it sends the identical batch again.
        $second = $this->replay([$this->queued('temp-once')]);
        $verdict = $this->verdict($second, 'temp-once');

        $this->assertSame('duplicate', $verdict['status']);
        $this->assertSame($id, $verdict['id'],
            'The till drops the sale from its queue on this id; it must be the sale that exists.');
        $this->assertSame($after, $this->sellCount(), 'A replay must not ring the sale up again.');
    }

    #[Test]
    public function duplicate_carries_the_invoice_number_so_the_till_can_finish_the_receipt(): void
    {
        $first = $this->replay([$this->queued('temp-invoice')]);
        $second = $this->replay([$this->queued('temp-invoice')]);

        $this->assertSame(
            $this->verdict($first, 'temp-invoice')['invoice_no'],
            $this->verdict($second, 'temp-invoice')['invoice_no']
        );
    }

    #[Test]
    public function the_unique_index_refuses_a_second_sale_with_the_same_temp_id(): void
    {
        /*
         * The layer under the controller, asserted directly. The lookup in
         * `replayOne()` is what produces a useful answer; this index is what
         * makes that answer true when two requests race past the lookup before
         * either commits. If the index were a plain one — which it was until this
         * item — every test above would still pass and the constraint would be
         * doing nothing.
         */
        $body = $this->replay([$this->queued('temp-indexed')]);
        $id = $this->verdict($body, 'temp-indexed')['id'];

        /*
         * The committed row, copied. Building one field by field would be a
         * second, weaker claim about which columns `transactions` requires, and a
         * NOT NULL column missed on the way would raise a *different* error that
         * this test would happily accept as proof. `invoice_no` and
         * `invoice_token` are re-minted so that nothing but the temp id could be
         * what the database objects to.
         */
        $row = (array) DB::table('transactions')->where('id', $id)->first();

        unset($row['id']);
        $row['invoice_no'] = 'DUP-'.uniqid();
        $row['invoice_token'] = 'tok-'.uniqid();

        try {
            DB::table('transactions')->insert($row);

            $this->fail('A second sale with the same offline_temp_id was accepted.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('offline_temp_id', $e->getMessage(),
                'A different unique constraint fired, so this test proves nothing about the queue.');
        }
    }

    #[Test]
    public function the_same_temp_id_twice_in_one_batch_is_recorded_once(): void
    {
        // A queue that was restored from a backup, or a client bug. Either way
        // the batch is not a set, and the endpoint must behave as though it were.
        $before = $this->sellCount();

        $body = $this->replay([
            $this->queued('temp-twice'),
            $this->queued('temp-twice'),
        ]);

        $statuses = collect($body['results'])->pluck('status')->all();

        $this->assertSame(['accepted', 'duplicate'], $statuses);
        $this->assertSame($before + 1, $this->sellCount());
    }

    /* ================================================================
     | Rejection — a bad sale must not take the good ones with it
     ================================================================ */

    #[Test]
    public function a_rejected_sale_is_reported_while_its_siblings_land(): void
    {
        $before = $this->sellCount();

        $body = $this->replay([
            // A product deleted while the till was offline. Making the batch
            // atomic would hold up a whole shift's takings for this one row.
            $this->queued('temp-bad', ['lines' => [[
                'variation_id' => 999999, 'quantity' => 1, 'unit_price' => 10,
            ]]]),
            $this->queued('temp-good'),
        ]);

        $bad = $this->verdict($body, 'temp-bad');
        $this->assertSame('rejected', $bad['status']);
        $this->assertNotEmpty($bad['message'], 'A person has to be able to act on this.');

        $this->assertSame('accepted', $this->verdict($body, 'temp-good')['status']);
        $this->assertSame($before + 1, $this->sellCount(), 'Only the good sale.');
    }

    #[Test]
    public function a_rejected_sale_stays_rejected_and_is_never_half_recorded(): void
    {
        $before = $this->sellCount();
        $bad = $this->queued('temp-stuck', ['contact_id' => 999999]);

        foreach (range(1, 2) as $attempt) {
            $verdict = $this->verdict($this->replay([$bad]), 'temp-stuck');

            $this->assertSame('rejected', $verdict['status'], 'Attempt '.$attempt);
        }

        $this->assertSame($before, $this->sellCount());
        $this->assertSame(0, Transaction::query()->where('offline_temp_id', 'temp-stuck')->count(),
            'A refused sale must leave no row behind for the retry to find.');
    }

    #[Test]
    public function a_sale_with_no_temp_id_is_refused_rather_than_recorded(): void
    {
        $before = $this->sellCount();

        $sale = $this->queued('unused');
        unset($sale['temp_id']);

        $verdict = $this->verdict($this->replay([$sale]), '#0');

        $this->assertSame('rejected', $verdict['status'],
            'Without an id there is no way to tell a retry from a new sale.');
        $this->assertSame($before, $this->sellCount());

        // Also proves the lang key exists: an untranslated key would come back
        // as the literal string `lang_v1.offline_sale_missing_id`.
        $this->assertStringNotContainsString('lang_v1.', $verdict['message']);
    }

    #[Test]
    public function a_sale_whose_only_line_has_no_quantity_is_refused(): void
    {
        $before = $this->sellCount();

        $verdict = $this->verdict($this->replay([
            $this->queued('temp-zero', ['lines' => [[
                'variation_id' => $this->variation->id, 'quantity' => 0, 'unit_price' => 10,
            ]]]),
        ]), 'temp-zero');

        /*
         * Caught by the `gt:0` rule, so the message is the validator's. The
         * `nothing_to_sell` guard behind it is a second layer for a quantity that
         * passes validation and still rounds away — it should stay there, but it
         * is not what answers here, and asserting a specific message would be
         * asserting which layer fired rather than that the sale was refused.
         */
        $this->assertSame('rejected', $verdict['status']);
        $this->assertNotEmpty($verdict['message']);
        $this->assertSame($before, $this->sellCount());
    }

    #[Test]
    public function a_batch_larger_than_the_per_request_cap_is_refused_as_a_whole(): void
    {
        // Not a policy about queue size — a bound on how much work one request
        // may do, so it cannot fail after committing half of it. The client
        // chunks its queue to match.
        $sales = [];

        for ($i = 0; $i < 26; $i++) {
            $sales[] = $this->queued('temp-cap-'.$i);
        }

        $this->postJson(route('offline.sync'), ['device_id' => 'till-a', 'sales' => $sales])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sales');
    }

    #[Test]
    public function an_empty_batch_is_a_validation_error_not_a_silent_success(): void
    {
        $this->postJson(route('offline.sync'), ['sales' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sales');
    }

    /* ================================================================
     | When the sale happened, and who synced it
     ================================================================ */

    #[Test]
    public function a_sale_taken_days_ago_keeps_the_date_the_money_changed_hands(): void
    {
        /*
         * The figure a shop reconciles against. A till that was offline through
         * Tuesday evening and syncs on Wednesday morning must not report
         * Tuesday's takings as Wednesday's: the drawer was counted on Tuesday
         * night, and that day would never balance again.
         */
        $body = $this->replay([$this->queued('temp-past', [
            'created_at' => now()->subDays(3)->setTime(12, 0)->toIso8601String(),
        ])]);

        $sale = Transaction::query()->findOrFail($this->verdict($body, 'temp-past')['id']);

        // Bracketed rather than compared exactly: the request runs in the
        // tenant's timezone and the test process does not, so an exact instant
        // would assert the timezone handling rather than the intent.
        $this->assertTrue($sale->transaction_date->lt(now()->subDays(2)),
            'The sale must be dated when it was taken, not when it synced.');
        $this->assertTrue($sale->transaction_date->gt(now()->subDays(4)));
        $this->assertTrue($sale->offline_created_at->lt(now()->subDays(2)));
    }

    #[Test]
    public function a_sale_dated_in_the_future_is_clamped_to_now(): void
    {
        /*
         * A till whose clock is wrong. Honouring it would file a sale into a
         * period that has not happened yet — possibly past the end of a month
         * that has already been reported on, where nothing would ever find it.
         * Clamping is at worst a few hours late and always inside an open period.
         */
        $body = $this->replay([$this->queued('temp-future', [
            'created_at' => now()->addDays(2)->toIso8601String(),
        ])]);

        $sale = Transaction::query()->findOrFail($this->verdict($body, 'temp-future')['id']);

        $this->assertTrue($sale->transaction_date->lt(now()->addDay()));
    }

    #[Test]
    public function an_unparseable_date_falls_back_to_now_rather_than_refusing_the_sale(): void
    {
        // A sale with no usable date is still a sale. Dated a little late beats
        // not recorded at all.
        $body = $this->replay([$this->queued('temp-nodate', ['created_at' => 'not a date'])]);

        $verdict = $this->verdict($body, 'temp-nodate');
        $this->assertSame('accepted', $verdict['status']);

        $sale = Transaction::query()->findOrFail($verdict['id']);
        $this->assertTrue($sale->transaction_date->gt(now()->subDay()));
    }

    #[Test]
    public function the_sale_is_attributed_to_the_signed_in_user_never_to_the_payload(): void
    {
        $impostor = User::create([
            'user_type' => 'user', 'first_name' => 'Impostor',
            'username' => 'offimp_'.uniqid(), 'password' => 'secret-pass',
            'language' => 'ar', 'status' => 'active',
            'business_id' => $this->businessId, 'allow_login' => 1,
        ]);

        // A client-supplied `created_by` would let anyone with a session file a
        // sale under someone else's name — and these are the sales nobody
        // watched being entered.
        $body = $this->replay([$this->queued('temp-author', [
            'created_by' => $impostor->id,
        ])]);

        $sale = Transaction::query()->findOrFail($this->verdict($body, 'temp-author')['id']);

        $this->assertSame($this->admin->id, $sale->created_by);
        $this->assertNotSame($impostor->id, $sale->created_by);
    }

    #[Test]
    public function a_per_sale_device_id_survives_a_queue_restored_onto_another_till(): void
    {
        $body = $this->replay(
            [
                $this->queued('temp-dev-own', ['device_id' => 'till-original']),
                $this->queued('temp-dev-batch', ['device_id' => null]),
            ],
            'till-replacement'
        );

        $own = Transaction::query()->findOrFail($this->verdict($body, 'temp-dev-own')['id']);
        $batch = Transaction::query()->findOrFail($this->verdict($body, 'temp-dev-batch')['id']);

        $this->assertSame('till-original', $own->offline_device_id,
            'The machine that took the sale, not the one that sent it.');
        $this->assertSame('till-replacement', $batch->offline_device_id);
    }

    #[Test]
    public function the_receipt_number_the_device_printed_is_kept_beside_the_real_one(): void
    {
        $body = $this->replay([$this->queued('temp-recno', ['invoice_no' => 'OFF-0007'])]);

        $sale = Transaction::query()->findOrFail($this->verdict($body, 'temp-recno')['id']);

        $this->assertSame('OFF-0007', $sale->offline_invoice_no);
        $this->assertNotSame('OFF-0007', $sale->invoice_no,
            "The server's sequence is the shop's book of record; a device-minted "
            .'number would put a gap or a collision in it.');
    }

    /* ================================================================
     | Permissions
     ================================================================ */

    #[Test]
    public function a_user_who_may_not_sell_cannot_sync_or_snapshot(): void
    {
        $this->actingAs($this->clerk([]));

        $this->postJson(route('offline.sync'), [
            'sales' => [$this->queued('temp-forbidden')],
        ])->assertForbidden();

        $this->getJson(route('offline.data'))->assertForbidden();
    }

    #[Test]
    public function a_sale_from_a_location_the_syncing_user_may_not_reach_is_rejected(): void
    {
        $other = $this->secondLocation();
        $before = $this->sellCount();

        // Permitted at the main store only. The queue on a till could have been
        // taken under one user's session and synced under another's, so the check
        // is per sale and not once per batch.
        $this->actingAs($this->clerk(['sell.create', 'location.'.$this->location->id]));

        $body = $this->replay([
            $this->queued('temp-loc-bad', ['location_id' => $other->id]),
            $this->queued('temp-loc-ok'),
        ]);

        $bad = $this->verdict($body, 'temp-loc-bad');
        $this->assertSame('rejected', $bad['status']);
        $this->assertStringNotContainsString('lang_v1.', $bad['message']);

        $this->assertSame('accepted', $this->verdict($body, 'temp-loc-ok')['status'],
            'One refused branch must not stop the branch the cashier does work at.');
        $this->assertSame($before + 1, $this->sellCount());
    }

    /**
     * A non-admin user holding exactly the permissions named.
     *
     * @param  array<int, string>  $permissions
     */
    private function clerk(array $permissions): User
    {
        $role = Role::findOrCreate('Offline clerk '.uniqid(), 'web');

        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $clerk = User::create([
            'user_type' => 'user', 'first_name' => 'Clerk',
            'username' => 'offclerk_'.uniqid(), 'password' => 'secret-pass',
            'language' => 'ar', 'status' => 'active',
            'business_id' => $this->businessId, 'allow_login' => 1,
        ]);

        $clerk->assignRole($role);

        return $clerk;
    }

    private function secondLocation(): BusinessLocation
    {
        return BusinessLocation::create([
            'business_id' => $this->businessId,
            'name' => 'Branch',
            'invoice_scheme_id' => InvoiceScheme::first()->id,
            'invoice_layout_id' => InvoiceLayout::first()->id,
            'is_active' => true,
        ]);
    }

    /* ================================================================
     | The snapshot — ScreensRenderTest skips it and points here
     ================================================================ */

    #[Test]
    public function the_snapshot_carries_the_same_row_shape_the_live_search_returns(): void
    {
        $response = $this->getJson(route('offline.data', ['location_id' => $this->location->id]));

        $response->assertOk()
            ->assertJsonStructure([
                'location_id', 'price_group_id', 'taken_at', 'truncated', 'count',
                'products' => ['*' => ['variation_id', 'product_id', 'text', 'name',
                    'sku', 'unit', 'enable_stock', 'qty_available', 'selling_price',
                    'purchase_price', 'tax_id', 'tax_type', 'image_url', 'search']],
            ]);

        $row = collect($response->json('products'))
            ->firstWhere('variation_id', $this->variation->id);

        $this->assertNotNull($row, 'A sellable, stocked product must be in the snapshot.');

        /*
         * The keys are `getProducts()`'s, deliberately: `renderProducts()` in the
         * POS must not be able to tell which source answered it. A rename here
         * that still returns 200 would leave the grid rendering blank tiles the
         * moment the uplink dropped, and nothing else in the suite would see it.
         */
        $this->assertSame(100.0, round((float) $row['qty_available'], 2),
            'The whole point of a snapshot is stock the cashier can trust.');
        $this->assertSame(10.0, round((float) $row['selling_price'], 2));
        $this->assertTrue($row['enable_stock']);
        $this->assertStringContainsString(
            mb_strtolower($row['sku']),
            $row['search'],
            'The device matches on this string on every keystroke; it must be pre-lowercased.'
        );
    }

    #[Test]
    public function the_snapshot_refuses_a_location_the_user_may_not_read(): void
    {
        $other = $this->secondLocation();

        $this->actingAs($this->clerk(['sell.create', 'location.'.$this->location->id]));

        /*
         * 403 rather than quietly widening to "all locations". Silently answering
         * a different question would hand a branch-restricted cashier the head
         * office's stock figures, and they would have no way to tell.
         */
        $this->getJson(route('offline.data', ['location_id' => $other->id]))
            ->assertForbidden();

        $this->getJson(route('offline.data', ['location_id' => $this->location->id]))
            ->assertOk();
    }

    #[Test]
    public function the_snapshot_costs_the_same_number_of_queries_however_many_products_it_holds(): void
    {
        $unit = Unit::first();

        // Warm the session and the permission cache, so the two measurements
        // below differ only by catalogue size.
        $this->getJson(route('offline.data', ['location_id' => $this->location->id]))->assertOk();

        $small = $this->queriesFor(fn () => $this->getJson(
            route('offline.data', ['location_id' => $this->location->id])
        )->assertOk());

        foreach (range(1, 6) as $i) {
            $this->makeProduct('Offline bulk '.$i, $unit->id);
        }

        $large = $this->queriesFor(fn () => $this->getJson(
            route('offline.data', ['location_id' => $this->location->id])
        )->assertOk());

        /*
         * The reason this endpoint exists rather than `getProducts()` with a
         * higher limit. That one resolves stock and price per row: at a
         * catalogue's worth it is two round trips per variation, so a shop with
         * 3,000 products does not get a slow snapshot, it gets one that times
         * out. Both are resolved here in one query each and joined in memory —
         * and an N+1 reintroduced later is invisible until a real catalogue hits
         * it, which is why this is asserted as a number and not as a limit.
         */
        $this->assertSame($small, $large,
            'Adding six products added '.($large - $small).' queries; the snapshot must not be N+1.');
    }

    private function queriesFor(callable $work): int
    {
        $this->queryTally = 0;

        $work();

        $tally = $this->queryTally;

        // Back to null, which is what stops the listener counting. Laravel has no
        // way to detach one, so the switch has to be in the closure.
        $this->queryTally = null;

        return $tally;
    }

    /* ================================================================
     | POS store — the two fields the terminal now sends
     ================================================================ */

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function posPayload(array $overrides = []): array
    {
        return array_merge([
            'location_id' => $this->location->id,
            'contact_id' => $this->customer->id,
            'lines' => [[
                'variation_id' => $this->variation->id,
                'quantity' => 1,
                'unit_price' => 10,
            ]],
        ], $overrides);
    }

    #[Test]
    public function the_terminal_can_stamp_its_write_ahead_id_on_an_online_sale(): void
    {
        $response = $this->post(route('pos.store'), $this->posPayload([
            'offline_temp_id' => 'pos-wal-1',
            'offline_device_id' => 'till-a',
        ]));

        $response->assertRedirect(route('pos.create'));

        $sale = Transaction::query()->where('offline_temp_id', 'pos-wal-1')->first();

        $this->assertNotNull($sale, 'The id the till queued the sale under must reach the row.');
        $this->assertSame('till-a', $sale->offline_device_id);

        // The acknowledgement the terminal needs to drop its local copy. Flashed
        // separately from `status`, which every screen's banner partial reads.
        $response->assertSessionHas('offline_acknowledged', 'pos-wal-1');
    }

    #[Test]
    public function two_ordinary_counter_sales_in_a_row_both_succeed(): void
    {
        /*
         * THE REGRESSION THIS FILE MOST NEEDS.
         *
         * A hidden field that was never filled in posts `''`, and `''` is a value
         * the unique index on (business_id, offline_temp_id) accepts exactly
         * once. Without the empty-string guard in `store()`, the first sale of
         * the day would go through and every one after it would be refused — on
         * the screen that takes the most sales in the application, with nothing
         * about being offline involved.
         */
        $before = $this->sellCount();

        foreach (range(1, 2) as $attempt) {
            $this->post(route('pos.store'), $this->posPayload([
                'offline_temp_id' => '',
                'offline_device_id' => '',
            ]))->assertRedirect(route('pos.create'));
        }

        $this->assertSame($before + 2, $this->sellCount(), 'Both sales must land.');
        $this->assertSame(
            2,
            Transaction::query()->where('type', TransactionTypes::SELL)
                ->whereNull('offline_temp_id')->count(),
            'An empty id must be stored as NULL — the only value that index treats as "no id".'
        );
    }

    #[Test]
    public function a_sale_that_went_through_online_is_reported_duplicate_when_the_queue_drains(): void
    {
        /*
         * The write-ahead log closing. The terminal queues every sale *before* it
         * posts, so a POST that dies in flight leaves the money recorded on the
         * device. When it succeeds, the redirect carries the acknowledgement and
         * the copy is dropped — but if that acknowledgement is lost (the tab is
         * closed on the way back), the entry is simply synced like any other, and
         * this is the answer it must get. Two paths, one outcome.
         */
        $this->post(route('pos.store'), $this->posPayload([
            'offline_temp_id' => 'pos-both-paths',
            'offline_device_id' => 'till-a',
        ]))->assertRedirect(route('pos.create'));

        $online = Transaction::query()->where('offline_temp_id', 'pos-both-paths')->firstOrFail();
        $after = $this->sellCount();

        $verdict = $this->verdict(
            $this->replay([$this->queued('pos-both-paths', ['lines' => [[
                'variation_id' => $this->variation->id, 'quantity' => 1, 'unit_price' => 10,
            ]]])]),
            'pos-both-paths'
        );

        $this->assertSame('duplicate', $verdict['status']);
        $this->assertSame($online->id, $verdict['id']);
        $this->assertSame($after, $this->sellCount(), 'The sale was already rung up.');
    }

    /* ================================================================
     | The connectivity probe
     ================================================================ */

    #[Test]
    public function the_ping_endpoint_answers_without_a_session(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        /*
         * The terminal probes this immediately before every sale, because the
         * header badge's answer can be a whole `ping_interval` stale and the sale
         * needs to know *now* which path to take. So it has to answer for a
         * signed-out browser and without touching the session store — the thing
         * most likely to be down when the network is.
         */
        $this->getJson(route('api.ping'))
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonStructure(['ok', 'time']);
    }
}
