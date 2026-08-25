<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Currency;
use App\Models\User;
use App\Modules\AssetManagement\Models\Asset;
use App\Modules\AssetManagement\Models\AssetMaintenance;
use App\Modules\AssetManagement\Models\AssetTransaction;
use App\Services\AssetService;
use App\Services\BusinessService;
use App\Services\FormattingService;
use App\Support\Permissions;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The fixed-asset register: the allocation arithmetic, and the edits it forbids.
 *
 * Almost everything worth testing here is *derived*. An asset's `quantity` column
 * says how many exist; how many are available is that minus a sum over two
 * transaction types, and nothing in the schema enforces the relationship between
 * them. There is no unique index, no check constraint and no foreign key that would
 * catch a register claiming five machines exist while six are signed out — only
 * {@see AssetService}, and only if it is right.
 *
 * That shapes the suite three ways:
 *
 * - **The arithmetic is asserted at every step of a round trip**, not only at the
 *   end. Allocate three of five, return one, return the rest: a sign error survives
 *   an end-state assertion — 0 out of 5 is what you get whether the returns were
 *   added or subtracted — and dies on the middle one.
 * - **Every refusal has its own test**, asserted on the flashed `status.msg` rather
 *   than on rendered HTML. Each is a `RuntimeException` the controller converts into
 *   a message, so a refusal that stopped firing would look like a successful save on
 *   screen: the loudest failure mode and the quietest possible test failure. The
 *   session is also the only place both exit paths agree on, since `allocate()` and
 *   `update()` fail back to the referrer while `revoke()` and `destroy()` fail
 *   forward to a named route.
 * - **The tenancy boundaries are asserted here rather than in {@see TenantScopeTest}**,
 *   which cannot reach them: its tenant has no modules enabled, so `requireModule()`
 *   answers 403 before validation runs. Its `COVERED_ELSEWHERE` names
 *   {@see self::an_asset_cannot_be_parked_at_another_tenants_branch} for
 *   `AssetController.php:location_id`, and that promise is what this file keeps.
 */
class AssetsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $businessId;

    private int $locationId;

    private int $foreignLocationId;

    private int $foreignUserId;

    private int $foreignAssetId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Seeded inside this test's transaction, so anything spatie cached during an
        // earlier test points at ids that no longer exist.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $currency = Currency::firstOrCreate(
            ['code' => 'EGP'],
            ['country' => 'Egypt', 'currency' => 'Egyptian Pound', 'symbol' => 'ج.م',
                'thousand_separator' => ',', 'decimal_separator' => '.']
        );

        /*
         * The rival first, and its asset built while its own tenancy is bound. Both
         * halves matter: reading the foreign branch id and creating the foreign asset
         * under the rival's own binding means no assertion here has to disable a
         * global scope to set up its fixture — which would be exercising the very
         * mechanism under test.
         */
        $rival = $this->register($currency->id, 'Rival Assets Co.');
        Tenancy::bind($rival['business']->id);
        $this->actingAs($rival['owner']);

        $this->foreignUserId = (int) $rival['owner']->id;
        $this->foreignLocationId = (int) BusinessLocation::query()->firstOrFail()->id;
        $this->foreignAssetId = (int) app(AssetService::class)->create([
            'name' => 'Rival forklift',
            'asset_code' => 'SHARED-CODE-1',
            'quantity' => 1,
            'unit_price' => 0,
            'is_allocatable' => true,
        ])->id;

        $own = $this->register($currency->id, 'Assets Co.');

        $this->admin = $own['owner'];
        $this->businessId = (int) $own['business']->id;

        Tenancy::bind($this->businessId);

        $this->locationId = (int) BusinessLocation::query()->firstOrFail()->id;

        $this->assertNotSame(
            $this->foreignLocationId,
            $this->locationId,
            'Fixture check: the rival must be a separate tenant with a branch of its own.'
        );

        $this->actingAs($this->admin);
    }

    /* ================================================================
     | The allocation round trip
     ================================================================ */

    #[Test]
    public function allocating_and_returning_moves_the_available_quantity_both_ways(): void
    {
        $asset = $this->asset(['quantity' => 5]);

        $this->assertSame(0.0, $asset->allocated_quantity);
        $this->assertSame(5.0, $asset->available_quantity);

        $this->post(route('assets.allocate', $asset->id), [
            'receiver' => $this->admin->id,
            'quantity' => 3,
        ])->assertRedirect(route('assets.show', $asset->id));

        $this->assertSame(3.0, $asset->fresh()->allocated_quantity);
        $this->assertSame(2.0, $asset->fresh()->available_quantity);

        $allocation = AssetTransaction::query()
            ->where('asset_id', $asset->id)->allocations()->firstOrFail();

        /*
         * One of the three back. This middle step is what distinguishes a correct sum
         * from a sign error: a run that only checked the end state would read 0 out of
         * 5 either way.
         */
        $this->post(route('assets.revoke', [$asset->id, $allocation->id]), ['quantity' => 1])
            ->assertRedirect(route('assets.show', $asset->id));

        $this->assertSame(2.0, $asset->fresh()->allocated_quantity);
        $this->assertSame(3.0, $asset->fresh()->available_quantity);
        $this->assertSame(2.0, $allocation->fresh()->quantity_outstanding);

        // And the rest, through the blank-quantity path the row's own button uses.
        $this->post(route('assets.revoke', [$asset->id, $allocation->id]))
            ->assertRedirect(route('assets.show', $asset->id));

        $this->assertSame(0.0, $asset->fresh()->allocated_quantity);
        $this->assertSame(5.0, $asset->fresh()->available_quantity);
        $this->assertSame(0.0, $allocation->fresh()->quantity_outstanding);
    }

    #[Test]
    public function a_revocation_records_itself_under_the_allocation_it_closes(): void
    {
        $asset = $this->asset(['quantity' => 2]);
        $allocation = $this->allocate($asset, 2);

        $this->post(route('assets.revoke', [$asset->id, $allocation->id]));

        $revocation = AssetTransaction::query()
            ->where('asset_id', $asset->id)->revocations()->firstOrFail();

        $this->assertSame($allocation->id, (int) $revocation->parent_id);

        /*
         * The same person, deliberately: `receiver` on a revocation reads as who it
         * came back *from*. Asserted because it is the one field a reasonable reader
         * would "fix" to null, and doing so would break the pairing that lets an
         * allocation and its return be read as one movement.
         */
        $this->assertSame((int) $allocation->receiver, (int) $revocation->receiver);
    }

    #[Test]
    public function an_allocation_beyond_what_is_available_is_refused(): void
    {
        $asset = $this->asset(['quantity' => 2]);
        $this->allocate($asset, 2);

        $this->post(route('assets.allocate', $asset->id), [
            'receiver' => $this->admin->id,
            'quantity' => 1,
        ])->assertSessionHas(
            'status.msg',
            $this->refusal('quantity_exceeds_available', ['available' => 0])
        );

        $this->assertSame(2.0, $asset->fresh()->allocated_quantity);
    }

    #[Test]
    public function an_asset_that_does_not_leave_the_branch_cannot_be_allocated(): void
    {
        $asset = $this->asset(['quantity' => 3, 'is_allocatable' => false]);

        $this->post(route('assets.allocate', $asset->id), [
            'receiver' => $this->admin->id,
            'quantity' => 1,
        ])->assertSessionHas('status.msg', __('assetmanagement.asset_not_allocatable'));

        $this->assertSame(0.0, $asset->fresh()->allocated_quantity);
    }

    #[Test]
    public function returning_more_than_is_outstanding_is_refused(): void
    {
        $asset = $this->asset(['quantity' => 5]);
        $allocation = $this->allocate($asset, 2);

        $this->post(route('assets.revoke', [$asset->id, $allocation->id]), ['quantity' => 3])
            ->assertSessionHas(
                'status.msg',
                $this->refusal('quantity_exceeds_outstanding', ['outstanding' => 2])
            );

        $this->assertSame(2.0, $asset->fresh()->allocated_quantity);
    }

    #[Test]
    public function returning_an_allocation_that_is_already_back_is_refused(): void
    {
        $asset = $this->asset(['quantity' => 1]);
        $allocation = $this->allocate($asset, 1);

        $this->post(route('assets.revoke', [$asset->id, $allocation->id]));

        $this->post(route('assets.revoke', [$asset->id, $allocation->id]))
            ->assertSessionHas('status.msg', __('assetmanagement.already_returned'));

        // One revocation, not two: the refusal has to prevent the row, not merely
        // report afterwards that it should not have been written.
        $this->assertSame(1, AssetTransaction::query()
            ->where('asset_id', $asset->id)->revocations()->count());
    }

    #[Test]
    public function a_revocation_row_cannot_itself_be_revoked(): void
    {
        $asset = $this->asset(['quantity' => 1]);
        $allocation = $this->allocate($asset, 1);

        $this->post(route('assets.revoke', [$asset->id, $allocation->id]));

        $revocation = AssetTransaction::query()
            ->where('asset_id', $asset->id)->revocations()->firstOrFail();

        /*
         * Reachable only by typing the id, since the screen never offers a Take-back
         * on a revocation row — which is exactly why it is worth a test. Without the
         * type check, revoking a revocation would add back quantity that never went
         * out, and the register would grow every time somebody refreshed.
         */
        $this->post(route('assets.revoke', [$asset->id, $revocation->id]))
            ->assertSessionHas('status.msg', __('assetmanagement.not_an_allocation'));

        $this->assertSame(0.0, $asset->fresh()->allocated_quantity);
    }

    /* ================================================================
     | The edits an outstanding allocation forbids
     ================================================================ */

    #[Test]
    public function the_quantity_cannot_be_cut_below_what_is_signed_out(): void
    {
        $asset = $this->asset(['quantity' => 5]);
        $this->allocate($asset, 4);

        $this->put(route('assets.update', $asset->id), $this->assetPayload([
            'quantity' => 3,
            'is_allocatable' => 1,
        ]))->assertSessionHas(
            'status.msg',
            $this->refusal('quantity_below_allocated', ['allocated' => 4])
        );

        $this->assertSame(5.0, $asset->fresh()->quantity);
    }

    #[Test]
    public function handing_out_cannot_be_switched_off_while_something_is_out(): void
    {
        $asset = $this->asset(['quantity' => 2]);
        $this->allocate($asset, 1);

        // No `is_allocatable` in the payload at all, which is what an unchecked box
        // submits — `$request->boolean()` reads its absence as false.
        $this->put(route('assets.update', $asset->id), $this->assetPayload(['quantity' => 2]))
            ->assertSessionHas('status.msg', __('assetmanagement.cannot_disable_allocation'));

        $this->assertTrue($asset->fresh()->is_allocatable);
    }

    #[Test]
    public function an_asset_with_something_out_cannot_be_deleted(): void
    {
        $asset = $this->asset(['quantity' => 2]);
        $this->allocate($asset, 1);

        $this->delete(route('assets.destroy', $asset->id))
            ->assertSessionHas('status.msg', __('assetmanagement.cannot_delete_allocated'));

        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
    }

    #[Test]
    public function deleting_an_asset_takes_its_warranties_and_maintenance_with_it(): void
    {
        $asset = $this->asset(['quantity' => 1]);

        $this->post(route('assets.warranties.store', $asset->id), [
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'additional_cost' => 50,
        ])->assertRedirect(route('assets.show', $asset->id));

        $maintenance = $this->maintenance($asset);

        $this->assertTrue($asset->fresh()->is_in_warranty);

        $this->delete(route('assets.destroy', $asset->id))
            ->assertRedirect(route('assets.index'));

        /*
         * `asset_warranties` and `asset_maintenances` index `asset_id` and stop there
         * — no foreign key, so no cascade — while `asset_transactions` does cascade.
         * Some children are deleted by hand in the service and some by the database,
         * and which is which is not guessable from the models, so it is asserted here.
         */
        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
        $this->assertDatabaseMissing('asset_warranties', ['asset_id' => $asset->id]);
        $this->assertDatabaseMissing('asset_maintenances', ['id' => $maintenance->id]);
    }

    /* ================================================================
     | Tenancy
     |
     | The promise TenantScopeTest::COVERED_ELSEWHERE makes on this module's behalf,
     | kept here because that suite's tenant has no modules enabled and gets a 403
     | from requireModule() before validation ever runs.
     ================================================================ */

    #[Test]
    public function an_asset_cannot_be_parked_at_another_tenants_branch(): void
    {
        $this->post(route('assets.store'), $this->assetPayload([
            'location_id' => $this->foreignLocationId,
        ]))->assertSessionHasErrors('location_id');

        $this->assertDatabaseMissing('assets', ['location_id' => $this->foreignLocationId]);

        // The control run, so the assertion above is about tenancy rather than about
        // `location_id` being rejected for some unrelated reason.
        $this->post(route('assets.store'), $this->assetPayload([
            'location_id' => $this->locationId,
        ]))->assertSessionHasNoErrors();
    }

    #[Test]
    public function another_tenants_asset_is_not_readable_editable_or_deletable(): void
    {
        foreach ([
            'show' => fn () => $this->get(route('assets.show', $this->foreignAssetId)),
            'edit' => fn () => $this->get(route('assets.edit', $this->foreignAssetId)),
            'update' => fn () => $this->put(route('assets.update', $this->foreignAssetId), $this->assetPayload()),
            'destroy' => fn () => $this->delete(route('assets.destroy', $this->foreignAssetId)),
            'allocate' => fn () => $this->post(route('assets.allocate', $this->foreignAssetId), [
                'receiver' => $this->admin->id, 'quantity' => 1,
            ]),
        ] as $action => $call) {
            $this->assertSame(404, $call()->status(), "assets.{$action} reached another tenant's asset.");
        }
    }

    #[Test]
    public function an_asset_code_is_unique_within_a_tenant_and_free_across_them(): void
    {
        // The rival already holds SHARED-CODE-1, and that must not block ours.
        $this->post(route('assets.store'), $this->assetPayload(['asset_code' => 'SHARED-CODE-1']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Asset::query()->forBusiness($this->businessId)
            ->where('asset_code', 'SHARED-CODE-1')->count());

        // Ours now does.
        $this->post(route('assets.store'), $this->assetPayload(['asset_code' => 'SHARED-CODE-1']))
            ->assertSessionHasErrors('asset_code');
    }

    #[Test]
    public function a_receiver_from_another_tenant_cannot_be_handed_an_asset(): void
    {
        $asset = $this->asset(['quantity' => 1]);

        $this->post(route('assets.allocate', $asset->id), [
            'receiver' => $this->foreignUserId,
            'quantity' => 1,
        ])->assertSessionHasErrors('receiver');

        $this->assertSame(0.0, $asset->fresh()->allocated_quantity);
    }

    /* ================================================================
     | The module switch
     ================================================================ */

    #[Test]
    public function every_asset_screen_is_behind_the_assetmanagement_module(): void
    {
        $asset = $this->asset(['quantity' => 1]);

        // The control, which also hydrates the session from the business row.
        $this->get(route('assets.index'))->assertOk();

        /*
         * Switched off where it actually lives, rather than by writing over the
         * session key: `SetSessionData` rebuilds `session('business')` from this
         * column whenever the session is cold, so a session-only override would be
         * overwritten on the very next request. Flushing afterwards is what forces
         * that rebuild to happen now — and `actingAs()` survives it, because it sets
         * the guard's user rather than a session key.
         */
        Business::query()->whereKey($this->businessId)->update([
            'enabled_modules' => json_encode(['account']),
        ]);

        $this->flushSession();

        foreach ([
            route('assets.index'),
            route('assets.create'),
            route('assets.show', $asset->id),
            route('assets.edit', $asset->id),
            route('asset-maintenance.index'),
            route('asset-maintenance.create'),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    /* ================================================================
     | Maintenance visibility
     ================================================================ */

    #[Test]
    public function a_technician_sees_only_the_jobs_they_raised_or_were_assigned(): void
    {
        $asset = $this->asset(['quantity' => 1]);

        $technician = $this->staff('access_all_locations', 'asset.view_own_maintenance', 'asset.update');
        $other = $this->staff('access_all_locations', 'asset.view_own_maintenance');

        $mine = $this->maintenance($asset, ['assigned_to' => $technician->id, 'details' => 'Mine to fix']);
        $theirs = $this->maintenance($asset, ['assigned_to' => $other->id, 'details' => 'Not mine']);

        $this->actingAs($technician)
            ->get(route('asset-maintenance.index'))
            ->assertOk()
            ->assertSee('Mine to fix')
            ->assertDontSee('Not mine');

        /*
         * And not by typing the id either. `findMaintenance()` routes through the same
         * restricted query as the list for exactly this reason — a visibility rule
         * that only filters a list is a filter, not a permission.
         */
        $this->actingAs($technician)
            ->get(route('asset-maintenance.edit', $theirs->id))
            ->assertNotFound();

        $this->actingAs($technician)
            ->get(route('asset-maintenance.edit', $mine->id))
            ->assertOk();
    }

    #[Test]
    public function the_wider_permission_sees_every_job_on_the_floor(): void
    {
        $asset = $this->asset(['quantity' => 1]);

        $supervisor = $this->staff('access_all_locations', 'asset.view_all_maintenance');
        $someone = $this->staff('asset.view');

        $this->maintenance($asset, ['assigned_to' => $someone->id, 'details' => 'Somebody elses job']);

        $this->actingAs($supervisor)
            ->get(route('asset-maintenance.index'))
            ->assertOk()
            ->assertSee('Somebody elses job');
    }

    #[Test]
    public function a_job_is_invisible_when_its_asset_sits_at_an_unreachable_branch(): void
    {
        $atBranch = $this->asset(['quantity' => 1]);
        $atNoBranch = $this->asset(['quantity' => 1, 'location_id' => null]);

        // No `access_all_locations` and no `location.*`, unlike the two tests above:
        // this one is about the branch restriction rather than about assignment.
        $technician = $this->staff('asset.view_own_maintenance', 'asset.update');

        $hidden = $this->maintenance($atBranch, [
            'assigned_to' => $technician->id, 'details' => 'Job at the branch',
        ]);
        $this->maintenance($atNoBranch, [
            'assigned_to' => $technician->id, 'details' => 'Job on head-office kit',
        ]);

        /*
         * Both halves of `Asset::scopePermitted()` in one assertion. A job at a branch
         * this reader cannot reach is not theirs to see *even though it is assigned to
         * them* — and an asset at no branch at all stays visible, which is the
         * deliberate carve-out that keeps the head-office register readable by people
         * whose access is limited to a shop.
         */
        $this->actingAs($technician)
            ->get(route('asset-maintenance.index'))
            ->assertOk()
            ->assertSee('Job on head-office kit')
            ->assertDontSee('Job at the branch');

        $this->actingAs($technician)
            ->get(route('asset-maintenance.edit', $hidden->id))
            ->assertNotFound();
    }

    #[Test]
    public function a_job_cannot_be_raised_against_another_tenants_asset(): void
    {
        $this->post(route('asset-maintenance.store'), [
            'asset_id' => $this->foreignAssetId,
            'status' => 'scheduled',
            'priority' => 'high',
        ])->assertSessionHasErrors('asset_id');

        $this->assertDatabaseMissing('asset_maintenances', ['asset_id' => $this->foreignAssetId]);
    }

    #[Test]
    public function a_job_keeps_its_asset_across_an_edit(): void
    {
        $first = $this->asset(['quantity' => 1]);
        $second = $this->asset(['quantity' => 1]);

        $job = $this->maintenance($first);

        $this->put(route('asset-maintenance.update', $job->id), [
            // Offered and ignored: `rules(forUpdate: true)` leaves `asset_id` out, so
            // a hand-rolled POST cannot move a job onto a different asset either.
            'asset_id' => $second->id,
            'status' => 'completed',
            'priority' => 'low',
        ])->assertRedirect(route('asset-maintenance.index'));

        $job->refresh();

        $this->assertSame($first->id, (int) $job->asset_id);
        $this->assertSame('completed', $job->status);
    }

    /* ================================================================
     | Figures on the list screen
     ================================================================ */

    #[Test]
    public function the_register_totals_count_assets_cost_and_what_is_still_out(): void
    {
        $out = $this->asset(['quantity' => 4, 'unit_price' => 100]);
        $this->asset(['quantity' => 2, 'unit_price' => 50]);

        $this->allocate($out, 3);
        $this->maintenance($out, ['status' => 'scheduled']);
        $this->maintenance($out, ['status' => 'completed']);

        $totals = app(AssetService::class)->summary(
            Asset::query()->forBusiness($this->businessId)
        );

        $this->assertSame(2, $totals['assets']);
        $this->assertSame(500.0, $totals['cost']);
        $this->assertSame(3.0, $totals['allocated_qty']);
        $this->assertSame(1, $totals['allocated_assets']);

        // Completed does not count as open. The other half of that clause — a NULL
        // status *does* count — cannot be reached through the service, which defaults
        // it to scheduled, so it is asserted separately below.
        $this->assertSame(1, $totals['open_maintenance']);
    }

    #[Test]
    public function a_job_with_no_status_still_counts_as_open(): void
    {
        $asset = $this->asset(['quantity' => 1]);

        /*
         * Written straight to the table, because nothing in the application can
         * produce this row: `status` is `required` in the controller and defaulted in
         * the service. The column is nullable all the same, and `NOT IN` drops NULLs
         * silently — so without the `whereNull` half of that clause a row with no
         * status would vanish from the one tile that exists to tell somebody there is
         * work outstanding.
         */
        AssetMaintenance::query()->insert([
            'business_id' => $this->businessId,
            'asset_id' => $asset->id,
            'maitenance_id' => 'NULL-STATUS-1',
            'status' => null,
            'created_by' => $this->admin->id,
        ]);

        $totals = app(AssetService::class)->summary(
            Asset::query()->forBusiness($this->businessId)
        );

        $this->assertSame(1, $totals['open_maintenance']);
    }

    #[Test]
    public function book_value_falls_with_the_depreciation_rate_and_stops_at_zero(): void
    {
        $asset = $this->asset([
            'quantity' => 1,
            'unit_price' => 1000,
            'depreciation' => 10,
            'purchase_date' => now()->subYears(2)->toDateString(),
        ]);

        $this->assertSame(1000.0, $asset->acquisition_cost);

        // Two years at 10% a year. Loose to the nearest pound, because the accessor
        // measures elapsed years as a float and a leap day is not a defect.
        $this->assertEqualsWithDelta(800.0, $asset->current_value, 1.0);

        $writtenOff = $this->asset([
            'quantity' => 1,
            'unit_price' => 1000,
            'depreciation' => 20,
            'purchase_date' => now()->subYears(9)->toDateString(),
        ]);

        // Nine years at 20% is 180% of cost. A register that reports a negative asset
        // is worse than one that reports a worthless one.
        $this->assertSame(0.0, $writtenOff->current_value);
    }

    /* ================================================================
     | Fixtures
     ================================================================ */

    /**
     * @return array{owner: User, business: Business}
     */
    private function register(int $currencyId, string $name): array
    {
        /*
         * `register()` and not `createTenant()`: "admin" is a role, and only register()
         * seeds it. `permit()` short-circuits on isAdmin(), so a tenant without the
         * role turns every assertion in this file into a 403.
         *
         * `assetmanagement` enabled, which is what makes any of these screens
         * reachable — and the one test that wants it off flips the column instead.
         */
        return app(BusinessService::class)->register(
            ['name' => $name, 'currency_id' => $currencyId,
                'enabled_modules' => ['assetmanagement']],
            ['first_name' => 'Admin', 'username' => 'assets_'.uniqid(),
                'password' => 'secret-pass', 'language' => 'en']
        );
    }

    private function asset(array $overrides = []): Asset
    {
        static $sequence = 0;
        $sequence++;

        return app(AssetService::class)->create(array_merge([
            'name' => 'Asset '.$sequence,
            'asset_code' => 'OWN-'.uniqid(),
            'quantity' => 1,
            'unit_price' => 0,
            'location_id' => $this->locationId,
            'is_allocatable' => true,
        ], $overrides));
    }

    private function allocate(Asset $asset, float $quantity): AssetTransaction
    {
        return app(AssetService::class)->allocate($asset, [
            'receiver' => $this->admin->id,
            'quantity' => $quantity,
        ]);
    }

    private function maintenance(Asset $asset, array $overrides = []): AssetMaintenance
    {
        return app(AssetService::class)->createMaintenance($asset, array_merge([
            'status' => 'scheduled',
            'priority' => 'medium',
        ], $overrides));
    }

    /**
     * A payload the asset form's rules accept, with `$overrides` applied last.
     */
    private function assetPayload(array $overrides = []): array
    {
        static $sequence = 0;
        $sequence++;

        return array_merge([
            'name' => 'Posted asset '.$sequence,
            'asset_code' => 'POST-'.uniqid(),
            'quantity' => 1,
            'is_allocatable' => 1,
        ], $overrides);
    }

    /**
     * A refusal message with its quantity placeholders filled the way the service
     * fills them.
     *
     * Formatted through `FormattingService` rather than written out as "2.00", so the
     * assertion is about which refusal fired and with what number — not about the
     * tenant's quantity precision, which is a setting and may differ.
     */
    private function refusal(string $key, array $quantities): string
    {
        $format = app(FormattingService::class);

        return __('assetmanagement.'.$key, array_map(
            fn ($value) => $format->quantity($value),
            $quantities
        ));
    }

    /**
     * A non-admin user holding exactly the permissions named, and nothing else.
     *
     * Callers that are not testing the branch restriction pass `access_all_locations`
     * explicitly, because the maintenance list narrows on the *asset's* branch:
     * without it such a user reads an empty list for a reason that has nothing to do
     * with what the test is asserting.
     */
    private function staff(string ...$permissions): User
    {
        static $sequence = 0;
        $sequence++;

        $user = User::create([
            'user_type' => 'user',
            'business_id' => $this->businessId,
            'first_name' => 'Staff '.$sequence,
            'username' => 'staff_'.uniqid(),
            'password' => 'secret-pass',
            'language' => 'en',
            'status' => 'active',
            'allow_login' => 1,
        ]);

        $user->givePermissionTo($permissions);

        return $user;
    }
}
