<?php

namespace Tests\Feature;

use App\Models\Barcode;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Currency;
use App\Models\InvoiceLayout;
use App\Models\InvoiceScheme;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\TaxRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BusinessService;
use App\Support\Permissions;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The settings layer's behaviour, as opposed to its markup.
 *
 * {@see ScreensRenderTest} already walks all nine settings screens and proves
 * they render, in Arabic, with balanced markup and no untranslated keys. What a
 * render walk cannot see is everything those screens are actually *for*:
 *
 * - **The session cache.** {@see \App\Http\Middleware\SetSessionData} copies the
 *   whole `business` row into the session, so saving settings has to invalidate
 *   it. A save that does not is invisible on the settings screen itself and wrong
 *   on every other screen in the app until the user logs out and back in.
 * - **Flat permissions.** The settings area is gated by four single names
 *   (`invoice_settings.access`, `barcode_settings.access`, `access_printers`,
 *   `business_settings.access`) rather than the four-verb groups
 *   {@see \App\Http\Controllers\Concerns\SimpleCrudController} assumes, so every
 *   settings subclass overrides `ability()` to say so. Getting that wrong is
 *   invisible under an admin — `permit()` short-circuits on `isAdmin()` — and a
 *   silent lockout for everybody else: exactly the shape of bug a render walk run
 *   as an admin can never catch.
 * - **The lockout guards.** Every destructive path on the users screen is a way
 *   to leave a business with nobody who can get back into it.
 * - **The tenancy seams.** `users`, `roles` and `barcodes` all lack the business
 *   global scope, each for its own reason, so each one filters by hand — and a
 *   hand-written filter is a hand-written filter.
 *
 * These tests run as a real Admin — via {@see BusinessService::register()} rather
 * than `createTenant()`, because "admin" is a *role* and only `register()` seeds
 * it — and, wherever the point is a gate, as a deliberately under-privileged user.
 */
class SettingsTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Those rows were created inside this test's transaction, so anything
        // spatie cached during an earlier test points at ids that no longer exist.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        ['owner' => $owner, 'business' => $business] = $this->tenant('Settings Co.');

        $this->owner = $owner;
        $this->businessId = $business->id;

        $this->actingAs($this->owner);
    }

    /* ================================================================
     | Business settings — the one row every other screen reads
     ================================================================ */

    #[Test]
    public function saving_business_settings_drops_the_cached_row_and_the_next_request_rebuilds_it(): void
    {
        $stale = Business::find($this->businessId)->toArray();

        $this->withSession([
            'user' => $this->sessionUser(),
            'business' => $stale,
            'currency' => ['currency_precision' => 2],
            'financial_year' => ['start' => '2026-01-01', 'end' => '2026-12-31'],
        ])
            ->put(route('business.settings.update'), $this->businessPayload(['currency_precision' => 3]))
            ->assertRedirect()
            ->assertSessionHas('status.success', 1)
            ->assertSessionMissing('business')
            ->assertSessionMissing('currency')
            ->assertSessionMissing('financial_year');

        $this->assertSame(3, (int) Business::find($this->businessId)->currency_precision);

        /*
         * The state the save leaves behind: `user` still cached, `business` gone.
         * The middleware has to notice and rebuild. If it only re-hydrates when
         * `user` is missing, then for the rest of that session every figure is
         * formatted with a null precision and no currency symbol, and
         * `session('business.enabled_modules')` — which gates the sidebar and the
         * entire role editor — reads as an empty list.
         */
        $this->withSession(['user' => $this->sessionUser()])
            ->get(route('business.settings'))
            ->assertOk()
            ->assertSessionHas('business', fn ($cached) => (int) $cached['currency_precision'] === 3);
    }

    #[Test]
    public function business_settings_cannot_reach_the_columns_the_screen_does_not_own(): void
    {
        /*
         * `business` carries around a hundred columns and this screen edits about
         * twenty-five of them. The other buckets — ownership, the active flag, the
         * gateway credentials — are not merely absent from the Blade; they have to
         * be unreachable, because `fill()` on a model guarded only by `id` would
         * take any of them from a crafted POST. What makes it safe is that
         * `fill()` is handed the *validated* array and nothing else, which is the
         * property under test here.
         *
         * `logo` used to be in this list. Item 9 gave it an upload layer, so it is
         * now a column the screen owns — but only as a file, never as a string,
         * which is asserted separately below.
         */
        $before = Business::find($this->businessId);

        $this->put(route('business.settings.update'), $this->businessPayload([
            'owner_id' => 999999,
            'is_active' => 0,
            'email_settings' => ['password' => 'leaked'],
        ]))->assertRedirect()->assertSessionHas('status.success', 1);

        $after = Business::find($this->businessId);

        $this->assertSame($before->owner_id, $after->owner_id);
        $this->assertSame((bool) $before->is_active, (bool) $after->is_active);
        $this->assertSame($before->logo, $after->logo);
        $this->assertSame($before->email_settings, $after->email_settings);
    }

    #[Test]
    public function the_logo_column_only_ever_takes_a_real_uploaded_image(): void
    {
        /*
         * The column stores a *filename*, and everything downstream —
         * {@see \App\Services\UploadService::url()},
         * {@see \App\Services\UploadService::path()} and, through it, the DomPDF
         * invoice — resolves that name against a directory on disk. So a text
         * `logo` in the payload is the interesting attack: were the rule
         * `nullable|string`, a POST of `../../.env` would be written to the column
         * and then handed to a path resolver. `image` refuses it here, and
         * `UploadService::path()` refuses any name whose `basename()` differs from
         * itself as the second layer.
         */
        $before = Business::find($this->businessId)->logo;

        $this->from(route('business.settings'))
            ->put(route('business.settings.update'), $this->businessPayload([
                'logo' => '../../.env',
            ]))
            ->assertRedirect(route('business.settings'))
            ->assertSessionHasErrors('logo');

        $this->assertSame($before, Business::find($this->businessId)->logo);

        // And a save that never touches the file input leaves the stored logo
        // alone rather than nulling it — the bug `unset($validated['logo'])`
        // exists to prevent, which would otherwise erase a tenant's letterhead
        // every time somebody changed a time zone.
        Business::whereKey($this->businessId)->update(['logo' => 'existing-logo.png']);

        $this->put(route('business.settings.update'), $this->businessPayload())
            ->assertSessionHas('status.success', 1);

        $this->assertSame('existing-logo.png', Business::find($this->businessId)->logo);
    }

    #[Test]
    public function a_real_upload_lands_on_disk_and_the_remove_box_takes_it_away(): void
    {
        /*
         * The round trip, because every logo assertion above is about what the
         * column *refuses*. This one is about what happens when a tenant does the
         * ordinary thing.
         *
         * The file is named in Arabic on purpose. `Str::slug()` renders Arabic to
         * an empty string, so the naming path in
         * {@see \App\Services\UploadService::fileName()} falls through to its
         * `'file'` base — and in an Arabic-first product (Decision #3) a logo
         * called `شعار.png` is the normal case, not the exotic one. A stored name
         * that ended in a bare `_.png`, or a crash on the empty slug, would be
         * found by a tenant rather than by us.
         *
         * Three properties, in the order a tenant meets them: the upload is stored
         * under a bare filename and readable on disk; a second upload replaces the
         * first rather than accumulating (the `$replacing` argument); and the
         * remove box clears both the column and the file.
         */
        $directory = public_path(config('constants.business_logo_path'));

        $this->put(route('business.settings.update'), $this->businessPayload([
            'logo' => UploadedFile::fake()->image('شعار المتجر.png', 400, 200),
        ]))->assertSessionHas('status.success', 1);

        $first = Business::find($this->businessId)->logo;

        $this->assertNotNull($first);
        // A bare filename, never a path — the convention `UploadService::path()`
        // leans on, and what keeps the upload root a config value rather than
        // something baked into every stored row.
        $this->assertSame($first, basename($first));
        $this->assertStringEndsWith('.png', $first);
        $this->assertFileExists($directory.DIRECTORY_SEPARATOR.$first);

        $this->put(route('business.settings.update'), $this->businessPayload([
            'logo' => UploadedFile::fake()->image('new-logo.png', 400, 200),
        ]))->assertSessionHas('status.success', 1);

        $second = Business::find($this->businessId)->logo;

        $this->assertNotSame($first, $second);
        $this->assertFileExists($directory.DIRECTORY_SEPARATOR.$second);
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.$first);

        $this->put(route('business.settings.update'), $this->businessPayload([
            'remove_logo' => '1',
        ]))->assertSessionHas('status.success', 1);

        $this->assertNull(Business::find($this->businessId)->logo);
        $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.$second);
    }

    #[Test]
    public function an_unticked_feature_toggle_is_saved_as_off(): void
    {
        /*
         * An unticked checkbox is absent from the payload rather than present and
         * false, so a toggle read from the validated array can be switched on and
         * then never switched off again. The loop over `productToggles()` reading
         * `$request->boolean()` is what prevents that; this asserts the loop
         * actually covers the toggles the form renders.
         */
        Business::where('id', $this->businessId)
            ->update(['enable_brand' => 1, 'enable_lot_number' => 0]);

        $this->put(route('business.settings.update'), $this->businessPayload([
            // `enable_brand` deliberately absent: the browser sends nothing at all
            // for a box the user has just cleared.
            'enable_lot_number' => '1',
        ]))->assertRedirect()->assertSessionHas('status.success', 1);

        $business = Business::find($this->businessId);

        $this->assertFalse((bool) $business->enable_brand, 'A cleared checkbox never turned itself off.');
        $this->assertTrue((bool) $business->enable_lot_number, 'A ticked checkbox did not turn on.');
    }

    #[Test]
    public function the_settings_form_rejects_values_that_belong_to_somebody_else(): void
    {
        ['business' => $other] = $this->tenant('Rival Co.');

        Tenancy::bind($this->businessId);
        $this->actingAs($this->owner);

        $foreignTax = TaxRate::forBusiness($other->id)->firstOrFail();

        $this->put(route('business.settings.update'), $this->businessPayload([
            'default_sales_tax' => $foreignTax->id,
        ]))->assertSessionHasErrors('default_sales_tax');

        // `superadmin` is absent from availableModules() on purpose: it governs
        // other businesses' subscriptions, so a business must not be able to grant
        // itself the group simply by naming it in the payload.
        $this->put(route('business.settings.update'), $this->businessPayload([
            'enabled_modules' => ['purchase_order', 'superadmin'],
        ]))->assertSessionHasErrors('enabled_modules.1');

        $this->assertNotContains(
            'superadmin',
            (array) Business::find($this->businessId)->enabled_modules
        );
    }

    /* ================================================================
     | Flat settings permissions
     ================================================================ */

    #[Test]
    public function each_settings_area_answers_to_exactly_one_flat_permission(): void
    {
        $areas = [
            'invoice_settings.access' => ['invoice-schemes.index', 'invoice-layouts.index'],
            'barcode_settings.access' => ['barcodes.index'],
            'access_printers' => ['printers.index'],
            'business_settings.access' => ['business.settings', 'business-location.index',
                'notification-templates.index'],
        ];

        $everyScreen = array_merge(...array_values($areas));

        // Collected rather than asserted one by one: `assertOk()` takes no message
        // argument, so a bare failure would say "expected 200, got 403" without
        // naming which of twenty-eight combinations it was.
        $wrong = [];

        foreach ($areas as $permission => $ownScreens) {
            $this->actingAs($this->restricted([$permission]));

            foreach ($everyScreen as $route) {
                $expected = in_array($route, $ownScreens, true) ? 200 : 403;
                $actual = $this->get(route($route))->status();

                if ($actual !== $expected) {
                    $wrong[] = "`{$permission}` on `{$route}`: expected {$expected}, got {$actual}";
                }
            }
        }

        $this->assertSame([], $wrong);
    }

    #[Test]
    public function a_flat_settings_permission_covers_the_write_verbs_too(): void
    {
        /*
         * The trap `SimpleCrudController::ability()` exists to let subclasses
         * avoid. The base concatenates the verb, so a settings subclass that
         * forgot the override would check `invoice_settings.access.create` — a
         * name nobody holds and no seeder creates. Under an admin that is
         * invisible; for everybody else the create button renders and then 403s
         * on submit.
         */
        $this->actingAs($this->restricted(['invoice_settings.access']));

        $this->get(route('invoice-schemes.create'))->assertOk();

        $this->post(route('invoice-schemes.store'), [
            'name' => 'Limited scheme', 'scheme_type' => 'blank',
            'number_type' => 'sequential', 'prefix' => 'LIM',
            'start_number' => 1, 'total_digits' => 4,
        ])->assertRedirect(route('invoice-schemes.index'))->assertSessionHas('status.success', 1);

        $scheme = InvoiceScheme::forBusiness($this->businessId)
            ->where('name', 'Limited scheme')->firstOrFail();

        $this->get(route('invoice-schemes.edit', $scheme->id))->assertOk();

        $this->delete(route('invoice-schemes.destroy', $scheme->id))
            ->assertRedirect(route('invoice-schemes.index'))
            ->assertSessionHas('status.success', 1);

        $this->assertNull(InvoiceScheme::forBusiness($this->businessId)->find($scheme->id));
    }

    /* ================================================================
     | Notification templates — sixteen fixed types, no rows to begin with
     ================================================================ */

    #[Test]
    public function a_notification_template_is_created_on_first_save_and_updated_after(): void
    {
        $this->assertSame(0, NotificationTemplate::where('template_for', 'new_sale')->count());

        $this->put(route('notification-templates.update', 'new_sale'), [
            'subject' => 'Your invoice {invoice_number}',
            'email_body' => 'Thank you for your purchase.',
            'auto_send' => '1',
        ])->assertRedirect(route('notification-templates.index'))
            ->assertSessionHas('status.success', 1);

        $rows = NotificationTemplate::where('template_for', 'new_sale')->get();

        $this->assertCount(1, $rows, 'The first save did not create the row it had to create.');
        $this->assertTrue((bool) $rows->first()->auto_send);

        /*
         * `notification_templates` carries no unique index on
         * (business_id, template_for), so nothing at the database level would stop
         * a second save from inserting a second row — and the screen reads
         * whichever comes back first, so roughly half the edits would appear to
         * have been lost.
         */
        $this->put(route('notification-templates.update', 'new_sale'), [
            'subject' => 'Changed',
        ])->assertRedirect()->assertSessionHas('status.success', 1);

        $rows = NotificationTemplate::where('template_for', 'new_sale')->get();

        $this->assertCount(1, $rows, 'The second save inserted a duplicate instead of updating.');
        $this->assertSame('Changed', $rows->first()->subject);
        $this->assertFalse((bool) $rows->first()->auto_send, 'An unticked auto-send stayed on.');
    }

    #[Test]
    public function an_unknown_notification_type_is_a_404_and_writes_nothing(): void
    {
        // `{template}` is a slug straight off the URL, which makes it the one
        // place in the settings area where the route parameter is not an id the
        // database would reject on its own.
        $this->get(route('notification-templates.edit', 'not_a_template'))->assertNotFound();

        $this->put(route('notification-templates.update', 'not_a_template'), [
            'subject' => 'x',
        ])->assertNotFound();

        $this->assertSame(0, NotificationTemplate::count());
    }

    /* ================================================================
     | Barcodes — the one settings table shared between tenants
     ================================================================ */

    #[Test]
    public function a_shared_sticker_preset_is_visible_but_never_writable(): void
    {
        $global = Barcode::create([
            'business_id' => null, 'name' => 'Avery 5160',
            'width' => 2.5, 'height' => 1, 'stickers_in_one_row' => 3,
            'is_default' => false,
        ]);

        $this->get(route('barcodes.index'))->assertOk()->assertSee('Avery 5160');

        // Reads are deliberately wider than writes: indexQuery() lists own plus
        // global, findRecord() narrows the mutating verbs back to own-only.
        $this->get(route('barcodes.edit', $global->id))->assertNotFound();
        $this->put(route('barcodes.update', $global->id), ['name' => 'Hijacked'])->assertNotFound();

        // destroy() resolves the record inside its try/catch, so a foreign row
        // comes back as a failed banner rather than a 404 — either way the row
        // every other tenant shares has to survive.
        $this->delete(route('barcodes.destroy', $global->id))
            ->assertRedirect(route('barcodes.index'))
            ->assertSessionHas('status.success', 0);

        $this->assertSame('Avery 5160', $global->fresh()->name);
    }

    #[Test]
    public function setting_a_default_sticker_sheet_leaves_the_shared_presets_alone(): void
    {
        $globalDefault = Barcode::create([
            'business_id' => null, 'name' => 'Global default', 'is_default' => true,
        ]);
        $ownFirst = Barcode::create([
            'business_id' => $this->businessId, 'name' => 'Own first', 'is_default' => true,
        ]);

        $this->post(route('barcodes.store'), [
            'name' => 'Own second', 'stickers_in_one_row' => 2, 'is_default' => '1',
        ])->assertRedirect(route('barcodes.index'))->assertSessionHas('status.success', 1);

        $this->assertFalse(
            (bool) $ownFirst->fresh()->is_default,
            "The tenant's previous default was not cleared, so two sheets claim to be the default."
        );
        $this->assertTrue(
            (bool) $globalDefault->fresh()->is_default,
            'One tenant rewrote a preset every tenant shares.'
        );

        $created = Barcode::where('business_id', $this->businessId)
            ->where('name', 'Own second')->firstOrFail();

        $this->assertTrue((bool) $created->is_default);
    }

    /* ================================================================
     | Invoice schemes
     ================================================================ */

    #[Test]
    public function editing_a_scheme_never_rewinds_its_counter(): void
    {
        $scheme = InvoiceScheme::forBusiness($this->businessId)->firstOrFail();
        $scheme->update(['invoice_count' => 7]);

        $this->put(route('invoice-schemes.update', $scheme->id), [
            'name' => 'Renamed', 'scheme_type' => 'blank', 'number_type' => 'sequential',
            'prefix' => 'INV', 'start_number' => 1, 'total_digits' => 4,
            // Hand-crafted: the counter belongs to the number generator, and
            // rewinding it re-issues invoice numbers that are already on paper.
            'invoice_count' => 0,
        ])->assertRedirect(route('invoice-schemes.index'))->assertSessionHas('status.success', 1);

        $scheme->refresh();

        $this->assertSame(7, (int) $scheme->invoice_count, 'The invoice counter was taken from input.');
        $this->assertSame('Renamed', $scheme->name);
    }

    #[Test]
    public function a_scheme_a_location_numbers_its_invoices_with_cannot_be_deleted(): void
    {
        $scheme = InvoiceScheme::forBusiness($this->businessId)->firstOrFail();

        $this->assertTrue(
            BusinessLocation::forBusiness($this->businessId)
                ->where('invoice_scheme_id', $scheme->id)->exists(),
            'Fixture check: the seeded location should already point at the seeded scheme.'
        );

        // `business_locations.invoice_scheme_id` is NOT NULL behind a foreign key,
        // so deleting the scheme is a database error rather than a tidy-up.
        $this->delete(route('invoice-schemes.destroy', $scheme->id))
            ->assertRedirect(route('invoice-schemes.index'))
            ->assertSessionHas('status.success', 0);

        $this->assertNotNull(InvoiceScheme::forBusiness($this->businessId)->find($scheme->id));
    }

    /* ================================================================
     | Locations
     ================================================================ */

    #[Test]
    public function a_new_branch_gets_the_permission_that_makes_it_selectable(): void
    {
        $this->post(route('business-location.store'), $this->locationPayload(['name' => 'Nasr City']))
            ->assertRedirect(route('business-location.index'))
            ->assertSessionHas('status.success', 1);

        $branch = BusinessLocation::forBusiness($this->businessId)
            ->where('name', 'Nasr City')->firstOrFail();

        /*
         * Location access is a permission per location, so a branch whose
         * `location.<id>` row does not exist cannot be granted to anybody —
         * including the admin who just created it. The failure then looks like a
         * saving bug rather than the permission bug it is.
         */
        $this->assertTrue(
            Permission::where('name', Permissions::forLocation($branch->id))->exists(),
            'The branch was created without the permission that grants access to it.'
        );

        $this->assertTrue((bool) $branch->is_active, 'A new branch should start active.');
    }

    #[Test]
    public function a_branch_with_history_is_deactivated_rather_than_deleted(): void
    {
        $this->post(route('business-location.store'), $this->locationPayload(['name' => 'Maadi']))
            ->assertRedirect();

        $branch = BusinessLocation::forBusiness($this->businessId)
            ->where('name', 'Maadi')->firstOrFail();

        Transaction::create([
            'business_id' => $this->businessId,
            'location_id' => $branch->id,
            'type' => 'sell',
            'status' => 'final',
            'payment_status' => 'paid',
            'transaction_date' => now(),
            'final_total' => 100,
            'created_by' => $this->owner->id,
        ]);

        $this->delete(route('business-location.destroy', $branch->id))
            ->assertRedirect(route('business-location.index'))
            ->assertSessionHas('status.success', 0);

        $this->assertNotNull(BusinessLocation::forBusiness($this->businessId)->find($branch->id));

        // The soft alternative the block points at: history kept, nothing new
        // bookable against it.
        $this->get(route('business-location.toggle', $branch->id))
            ->assertRedirect(route('business-location.index'))
            ->assertSessionHas('status.success', 1);

        $this->assertFalse((bool) $branch->fresh()->is_active);

        $this->get(route('business-location.toggle', $branch->id));

        $this->assertTrue((bool) $branch->fresh()->is_active, 'The toggle only flips one way.');
    }

    /* ================================================================
     | Roles
     ================================================================ */

    #[Test]
    public function admin_and_cashier_are_reserved_however_they_are_typed(): void
    {
        /*
         * A second "admin" is not itself an escalation — isAdmin() matches the
         * literal `Admin#<id>` — but on screen it is indistinguishable from the
         * role that is, which is its own kind of dangerous. The `#suffix` is
         * stripped before the check, so `admin#7` cannot slip past it either.
         */
        foreach (['Admin', 'admin', 'ADMIN', 'Cashier', 'cashier', 'admin#'.$this->businessId] as $attempt) {
            $this->post(route('roles.store'), ['name' => $attempt, 'permissions' => ['product.view']])
                ->assertSessionHasErrors('name');
        }

        $this->assertSame(2, Role::forBusiness($this->businessId)->count());
    }

    #[Test]
    public function a_hand_crafted_suffix_cannot_reach_another_tenants_namespace(): void
    {
        $this->post(route('roles.store'), ['name' => 'Manager#999999', 'permissions' => ['product.view']])
            ->assertRedirect(route('roles.index'))
            ->assertSessionHas('status.success', 1);

        $this->assertNull(
            Role::where('name', 'Manager#999999')->first(),
            'A role was created inside a namespace that is not this tenant.'
        );
        $this->assertNotNull(Role::where('name', Role::nameFor('Manager', $this->businessId))->first());
    }

    #[Test]
    public function the_seeded_roles_cannot_be_deleted_and_neither_can_a_role_somebody_holds(): void
    {
        $admin = Role::forBusiness($this->businessId)
            ->where('name', Role::nameFor('Admin', $this->businessId))->firstOrFail();

        $this->delete(route('roles.destroy', $admin->id))
            ->assertRedirect(route('roles.index'))
            ->assertSessionHas('status.success', 0);

        $this->assertNotNull(Role::find($admin->id));

        $staff = $this->restricted(['product.view'], 'Holder');
        $held = $staff->roles()->firstOrFail();

        $this->actingAs($this->owner);

        $this->delete(route('roles.destroy', $held->id))->assertSessionHas('status.success', 0);
        $this->assertNotNull(Role::find($held->id));

        // Freed, the same delete goes through — so the block is about the users
        // holding the role and not about the role itself.
        $staff->syncRoles([]);

        $this->delete(route('roles.destroy', $held->id))->assertSessionHas('status.success', 1);
        $this->assertNull(Role::find($held->id));
    }

    #[Test]
    public function a_disabled_modules_permission_cannot_be_granted_by_a_crafted_post(): void
    {
        $this->assertNotContains(
            'essentials',
            (array) Business::find($this->businessId)->enabled_modules,
            'Fixture check: this tenant must not have the HR module enabled.'
        );

        $this->post(route('roles.store'), [
            'name' => 'Supervisor',
            // The second name is never rendered on the form for this tenant, so it
            // can only ever arrive from a hand-written request.
            'permissions' => ['product.view', 'essentials.add_todos'],
        ])->assertRedirect(route('roles.index'))->assertSessionHas('status.success', 1);

        $granted = Role::where('name', Role::nameFor('Supervisor', $this->businessId))
            ->firstOrFail()->permissions->pluck('name')->all();

        $this->assertContains('product.view', $granted);
        $this->assertNotContains(
            'essentials.add_todos',
            $granted,
            'A permission from a module the business has not enabled was granted anyway.'
        );
    }

    #[Test]
    public function updating_the_admin_role_never_rewrites_its_name_or_its_permissions(): void
    {
        $admin = Role::forBusiness($this->businessId)
            ->where('name', Role::nameFor('Admin', $this->businessId))->firstOrFail();

        $this->put(route('roles.update', $admin->id), [
            'name' => 'Owner', 'permissions' => ['product.view'],
        ])->assertRedirect(route('roles.index'))->assertSessionHas('status.success', 1);

        $admin->refresh();

        // isAdmin() keys off the literal name, so renaming it would strip every
        // admin in the business of every permission at once, silently.
        $this->assertSame(Role::nameFor('Admin', $this->businessId), $admin->name);
        $this->assertCount(0, $admin->permissions, "Admin's permission set is meant to stay empty.");
    }

    /* ================================================================
     | Staff accounts
     ================================================================ */

    #[Test]
    public function the_only_admin_can_neither_be_demoted_nor_locked_out(): void
    {
        $adminRoleId = $this->roleId('Admin');
        $cashierRoleId = $this->roleId('Cashier');

        // PHP's `+` keeps the left operand, so each override below wins over the
        // otherwise-valid baseline.
        $intact = [
            'first_name' => $this->owner->first_name,
            'language' => 'ar',
            'status' => 'active',
            'allow_login' => '1',
            'role_id' => $adminRoleId,
        ];

        // Moved to another role.
        $this->put(route('users.update', $this->owner->id), ['role_id' => $cashierRoleId] + $intact)
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('status.success', 0);
        $this->assertTrue($this->owner->fresh()->hasRole(Role::find($adminRoleId)));

        // Login switched off.
        $this->put(route('users.update', $this->owner->id), ['allow_login' => '0'] + $intact)
            ->assertSessionHas('status.success', 0);
        $this->assertTrue((bool) $this->owner->fresh()->allow_login);

        // Marked inactive, which stops the login just as effectively.
        $this->put(route('users.update', $this->owner->id), ['status' => 'inactive'] + $intact)
            ->assertSessionHas('status.success', 0);
        $this->assertSame('active', $this->owner->fresh()->status);

        // An ordinary edit of the same account still saves: the guard is about
        // losing the last way in, not about the owner being uneditable.
        $this->put(route('users.update', $this->owner->id), ['first_name' => 'Renamed'] + $intact)
            ->assertSessionHas('status.success', 1);
        $this->assertSame('Renamed', $this->owner->fresh()->first_name);

        // And with a second admin in place the demotion goes through, so the guard
        // is counting admins rather than protecting one particular row.
        $this->post(route('users.store'), $this->userPayload([
            'first_name' => 'Deputy', 'role_id' => $adminRoleId,
        ]))->assertSessionHas('status.success', 1);

        $this->put(route('users.update', $this->owner->id), ['role_id' => $cashierRoleId] + $intact)
            ->assertSessionHas('status.success', 1);
        $this->assertFalse($this->owner->fresh()->hasRole(Role::find($adminRoleId)));
    }

    #[Test]
    public function the_delete_button_cannot_lock_the_business_out(): void
    {
        // Your own account, whoever you are: the request that removes it is the
        // last one that account will ever make.
        $this->delete(route('users.destroy', $this->owner->id))
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('status.success', 0);

        $this->assertDatabaseHas('users', ['id' => $this->owner->id, 'deleted_at' => null]);

        // And the last admin, deleted by somebody who genuinely holds `user.delete`.
        $deputy = $this->restricted(['user.view', 'user.create', 'user.update', 'user.delete'], 'Deputy');
        $this->actingAs($deputy);

        $this->delete(route('users.destroy', $this->owner->id))
            ->assertSessionHas('status.success', 0);
        $this->assertDatabaseHas('users', ['id' => $this->owner->id, 'deleted_at' => null]);

        // Own account again, from the other side of the same guard.
        $this->delete(route('users.destroy', $deputy->id))
            ->assertSessionHas('status.success', 0);

        // An ordinary account deleted by somebody else does go, so neither block is
        // simply "delete never works".
        $this->actingAs($this->owner);
        $this->delete(route('users.destroy', $deputy->id))
            ->assertSessionHas('status.success', 1);
        $this->assertSoftDeleted('users', ['id' => $deputy->id]);
    }

    #[Test]
    public function all_locations_and_an_explicit_list_are_mutually_exclusive(): void
    {
        $main = BusinessLocation::forBusiness($this->businessId)->firstOrFail();

        $this->post(route('users.store'), $this->userPayload([
            'first_name' => 'Sara',
            'access_all_locations' => '1',
            'location_ids' => [$main->id],
        ]))->assertRedirect(route('users.index'))->assertSessionHas('status.success', 1);

        $staff = User::where('business_id', $this->businessId)
            ->where('first_name', 'Sara')->firstOrFail();

        $held = $staff->getDirectPermissions()->pluck('name')->all();

        $this->assertContains('access_all_locations', $held);
        $this->assertNotContains(
            Permissions::forLocation($main->id),
            $held,
            'An explicit list was kept alongside "all locations", where it is decorative and misleading.'
        );

        // Switching to an explicit list has to drop the blanket grant, or a branch
        // opened next year silently becomes visible to somebody who was
        // deliberately given one.
        $this->put(route('users.update', $staff->id), $this->userPayload([
            'first_name' => 'Sara',
            'location_ids' => [$main->id],
        ]))->assertSessionHas('status.success', 1);

        $held = $staff->fresh()->getDirectPermissions()->pluck('name')->all();

        $this->assertContains(Permissions::forLocation($main->id), $held);
        $this->assertNotContains('access_all_locations', $held);
    }

    #[Test]
    public function a_blank_password_leaves_the_existing_one_and_the_username_alone(): void
    {
        $username = 'omar'.uniqid();

        $this->post(route('users.store'), $this->userPayload([
            'first_name' => 'Omar', 'username' => $username,
        ]))->assertSessionHas('status.success', 1);

        $staff = User::where('username', $username)->firstOrFail();
        $hash = $staff->password;

        $payload = $this->userPayload([
            'first_name' => 'Omar Renamed',
            // Both hand-crafted: the password field renders empty because it is not
            // a way to read the current one, and a username is an identity people
            // log in with rather than a settings toggle.
            'username' => 'hijacked',
        ]);
        unset($payload['password'], $payload['password_confirmation']);

        $this->put(route('users.update', $staff->id), $payload)
            ->assertSessionHas('status.success', 1);

        $staff->refresh();

        $this->assertSame($hash, $staff->password, 'A blank password field rewrote the hash.');
        $this->assertSame($username, $staff->username, 'The username turned out to be editable after all.');
        $this->assertSame('Omar Renamed', $staff->first_name);
    }

    #[Test]
    public function another_businesss_staff_and_roles_are_out_of_reach(): void
    {
        ['owner' => $stranger, 'business' => $other] = $this->tenant('Rival Co.');

        $foreignRole = Role::where('business_id', $other->id)
            ->where('name', Role::nameFor('Cashier', $other->id))->firstOrFail();

        Tenancy::bind($this->businessId);
        $this->actingAs($this->owner);

        // Neither `users` nor `roles` carries the tenant global scope — login has
        // to find a user before a tenant exists, and spatie owns the roles table —
        // so these hand-written filters are the only thing between one shop's
        // settings screen and another shop's staff.
        $this->get(route('users.edit', $stranger->id))->assertNotFound();
        $this->put(route('users.update', $stranger->id), $this->userPayload())->assertNotFound();
        $this->get(route('roles.edit', $foreignRole->id))->assertNotFound();

        // destroy() resolves inside its try, so this is a failed banner rather than
        // a 404 — what matters is that the row survives.
        $this->delete(route('users.destroy', $stranger->id))->assertSessionHas('status.success', 0);
        $this->assertDatabaseHas('users', ['id' => $stranger->id, 'deleted_at' => null]);

        // A crafted role_id must not hand one of our users another tenant's role:
        // with a role named `Admin#<other>`, that is a cross-tenant escalation.
        $this->post(route('users.store'), $this->userPayload(['role_id' => $foreignRole->id]))
            ->assertSessionHasErrors('role_id');

        $this->get(route('users.index'))->assertOk()->assertDontSee($stranger->username);
    }

    /* ================================================================
     | Fixtures
     ================================================================ */

    /**
     * A tenant whose owner really holds the Admin role.
     *
     * `createTenant()` builds a business and an owner but no roles, and "admin" is
     * a role: `permit()` short-circuits on `isAdmin()`, which matches the literal
     * name `Admin#<id>`. Only `register()` seeds that.
     *
     * @return array{business: Business, owner: User}
     */
    private function tenant(string $name): array
    {
        $currency = Currency::firstOrCreate(
            ['code' => 'EGP'],
            ['country' => 'Egypt', 'currency' => 'Egyptian Pound', 'symbol' => 'ج.م',
                'thousand_separator' => ',', 'decimal_separator' => '.']
        );

        $registered = app(BusinessService::class)->register(
            ['name' => $name, 'currency_id' => $currency->id],
            ['first_name' => 'Owner', 'username' => 'owner'.uniqid(),
                'password' => 'secret-pass', 'language' => 'ar']
        );

        Tenancy::bind($registered['business']->id);

        return $registered;
    }

    /**
     * A user of this tenant holding exactly the permissions given.
     *
     * The role is created through {@see Role::nameFor()} rather than spatie's own
     * `findOrCreate` so that `Role::forBusiness()` can see it, and `allow_login`
     * is set because {@see \App\Http\Middleware\CheckUserLogin} would otherwise
     * turn every 403 assertion into a 302 to /home — the test would then be
     * measuring the login gate instead of the permission gate it is about.
     *
     * @param  array<int, string>  $permissions
     */
    private function restricted(array $permissions, string $label = 'Limited'): User
    {
        $role = Role::create([
            'name' => Role::nameFor($label.uniqid(), $this->businessId),
            'business_id' => $this->businessId,
            'is_default' => false,
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($permissions);

        $staff = User::create([
            'user_type' => 'user',
            'business_id' => $this->businessId,
            'first_name' => $label,
            'username' => mb_strtolower($label).uniqid(),
            'password' => Hash::make('secret-pass'),
            'language' => 'ar',
            'status' => 'active',
            'allow_login' => 1,
        ]);

        $staff->assignRole($role);

        return $staff;
    }

    private function roleId(string $display): int
    {
        return (int) Role::forBusiness($this->businessId)
            ->where('name', Role::nameFor($display, $this->businessId))
            ->value('id');
    }

    /**
     * The session shape {@see \App\Http\Middleware\SetSessionData} writes, minus
     * the cached business — enough for the middleware to decide it has nothing
     * left to refresh.
     *
     * @return array<string, mixed>
     */
    private function sessionUser(): array
    {
        return ['id' => $this->owner->id, 'business_id' => $this->businessId];
    }

    /**
     * A complete settings submit. Every `required` rule is satisfied, so a test
     * can vary one field and know the rest is not what failed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function businessPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Settings Co.',
            'currency_id' => Business::find($this->businessId)->currency_id,
            'time_zone' => 'Africa/Cairo',
            'fy_start_month' => 1,
            'accounting_method' => 'fifo',
            'sell_price_tax' => 'includes',
            'date_format' => 'd/m/Y',
            'time_format' => '24',
            'currency_symbol_placement' => 'before',
            'currency_precision' => 2,
            'quantity_precision' => 2,
            'transaction_edit_days' => 30,
            'stock_expiry_alert_days' => 30,
            'enabled_modules' => ['purchase_order', 'account'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function locationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Second branch',
            'invoice_scheme_id' => InvoiceScheme::forBusiness($this->businessId)->value('id'),
            'invoice_layout_id' => InvoiceLayout::forBusiness($this->businessId)->value('id'),
            // `browser` keeps `printer_id` out of it; the required_if pair has its
            // own coverage on the render walk.
            'receipt_printer_type' => 'browser',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Staff',
            'username' => 'staff'.uniqid(),
            'password' => 'strong-pass-1',
            'password_confirmation' => 'strong-pass-1',
            'language' => 'ar',
            'status' => 'active',
            'allow_login' => '1',
            'role_id' => $this->roleId('Cashier'),
        ], $overrides);
    }
}
