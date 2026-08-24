<?php

namespace Tests\Feature;

use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Unit;
use App\Services\BusinessService;
use App\Services\PurchaseService;
use App\Services\SellService;
use App\Support\Permissions;
use App\Support\TransactionTypes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The JSON endpoints — §12.5.
 *
 * {@see ScreensRenderTest} walks every GET route that renders a view, and skips
 * these: a JSON response has no markup for its untranslated-key and
 * div-balance guards to inspect, so putting them in that walk would only assert
 * that they returned *something*. The result was a real hole — thirteen
 * endpoints in that class's SKIP list with no test of their own, and they are
 * not incidental: `products.list` is the POS product search, `contacts.search`
 * is every contact picker in the application, and `purchases.orderLines` is what
 * fills a purchase form from an order. Each is reached by JavaScript, so when one
 * breaks nothing appears on screen except a control that silently stops working.
 *
 * So this class asserts the three things a caller in JavaScript actually depends
 * on, none of which a render walk can see:
 *
 * 1. **A JSON content type**, not a redirect to /login and not an HTML error
 *    page. A `fetch()` given HTML fails at `.json()`, far away from the cause.
 * 2. **The field names the front end binds to.** A rename that leaves the
 *    endpoint returning 200 breaks every caller, and nothing else would catch it.
 * 3. **The failure modes**: a missing id is a 404, a missing required parameter
 *    is a 422, and a user without the permission gets 403 — as JSON in each
 *    case, because these are called with `Accept: application/json`.
 *
 * `Product::scopeForLocation()` is tested here too, for the reason its own
 * docblock gives: the wrong table qualifier there is a SQL error rather than an
 * empty result, and it took the POS product search down once already.
 */
class ApiResponseTest extends TestCase
{
    use DatabaseTransactions;

    private \App\Models\User $admin;

    private int $businessId;

    private Product $product;

    private Contact $supplier;

    private Contact $customer;

    private int $purchaseOrderId;

    private int $salesOrderId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $currency = \App\Models\Currency::firstOrCreate(
            ['code' => 'EGP'],
            ['country' => 'Egypt', 'currency' => 'Egyptian Pound', 'symbol' => 'ج.م',
                'thousand_separator' => ',', 'decimal_separator' => '.']
        );

        /*
         * A registered business rather than createTenant(), because
         * `Gate::before()` waves an admin past every permission check and
         * `isAdmin()` is a *role* — which only BusinessService::register()
         * creates. With createTenant()'s bare owner these tests would be
         * asserting 403s everywhere and passing for the wrong reason.
         */
        ['owner' => $owner, 'business' => $business] = app(BusinessService::class)->register(
            ['name' => 'Api Co.', 'currency_id' => $currency->id],
            ['first_name' => 'Admin', 'username' => 'api_'.uniqid(),
                'password' => 'secret-pass', 'language' => 'ar']
        );

        $this->admin = $owner;
        $this->businessId = $business->id;
        \App\Support\Tenancy::bind($business->id);

        $this->location = BusinessLocation::first();
        $this->seedFixtures();

        $this->actingAs($this->admin);
    }

    private function seedFixtures(): void
    {
        $unit = Unit::first() ?? Unit::create([
            'actual_name' => 'Pieces', 'short_name' => 'Pc',
            'allow_decimal' => 0, 'created_by' => $this->admin->id,
        ]);

        $this->product = Product::create([
            'name' => 'Api product', 'type' => 'single', 'unit_id' => $unit->id,
            'tax_type' => 'exclusive', 'enable_stock' => 1, 'alert_quantity' => 0,
            'sku' => 'API-'.uniqid(), 'barcode_type' => 'C128',
            'created_by' => $this->admin->id,
        ]);

        $productVariation = \App\Models\ProductVariation::create([
            'product_id' => $this->product->id, 'name' => 'DUMMY', 'is_dummy' => 1,
        ]);

        \App\Models\Variation::create([
            'product_id' => $this->product->id,
            'product_variation_id' => $productVariation->id,
            'name' => 'DUMMY', 'sub_sku' => $this->product->sku,
            'default_purchase_price' => 8, 'dpp_inc_tax' => 8,
            'profit_percent' => 25, 'default_sell_price' => 10,
            'sell_price_inc_tax' => 10,
        ]);

        $this->product = $this->product->fresh('variations');
        $variationId = $this->product->variations->first()->id;

        $this->supplier = Contact::create([
            'type' => 'supplier', 'name' => 'Api supplier',
            'supplier_business_name' => 'Api supplier', 'contact_status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $this->customer = Contact::create([
            'type' => 'customer', 'name' => 'Api customer',
            'first_name' => 'Api customer', 'contact_status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $header = [
            'location_id' => $this->location->id,
            'created_by' => $this->admin->id,
        ];

        $purchases = app(PurchaseService::class);

        // An outstanding order and an outstanding requisition: both endpoints
        // filter on status `ordered`/`partial`, which is what these default to.
        $this->purchaseOrderId = $purchases->create(
            $header + ['contact_id' => $this->supplier->id],
            [['product_id' => $this->product->id, 'variation_id' => $variationId,
                'quantity' => 10, 'purchase_price' => 8, 'purchase_price_inc_tax' => 8]],
            [],
            TransactionTypes::PURCHASE_ORDER
        )->id;

        $purchases->create(
            $header + ['contact_id' => $this->supplier->id],
            [['product_id' => $this->product->id, 'variation_id' => $variationId,
                'quantity' => 6, 'purchase_price' => 8, 'purchase_price_inc_tax' => 8]],
            [],
            TransactionTypes::PURCHASE_REQUISITION
        );

        $this->salesOrderId = app(SellService::class)->create(
            $header + ['contact_id' => $this->customer->id],
            [['product_id' => $this->product->id, 'variation_id' => $variationId,
                'quantity' => 4, 'unit_price' => 10, 'unit_price_inc_tax' => 10]],
            [],
            TransactionTypes::SALES_ORDER
        )->id;
    }

    /**
     * Every JSON endpoint, with the arguments it needs to answer properly.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    private function endpoints(): array
    {
        return [
            'status.clear' => ['status.clear', []],
            'notifications.unreadCount' => ['notifications.unreadCount', []],
            'products.list' => ['products.list', ['term' => 'Api', 'location_id' => $this->location->id]],
            'products.subUnits' => ['products.subUnits', ['unit_id' => Unit::first()->id]],
            'labels.products' => ['labels.products', ['term' => 'Api']],
            'contacts.search' => ['contacts.search', ['type' => 'customer', 'term' => 'Api']],
            'contacts.due' => ['contacts.due', ['id' => $this->customer->id]],
            'purchases.orderLines' => ['purchases.orderLines', ['order' => $this->purchaseOrderId]],
            'purchases.supplierOrders' => ['purchases.supplierOrders', ['contact' => $this->supplier->id]],
            'purchase-requisition.outstandingLines' => ['purchase-requisition.outstandingLines', []],
            'sells.orderLines' => ['sells.orderLines', ['order' => $this->salesOrderId]],
            'sells.customerOrders' => ['sells.customerOrders', ['contact' => $this->customer->id]],
        ];
    }

    /* ================================================================
     | The contract every caller depends on
     ================================================================ */

    #[Test]
    public function every_json_endpoint_answers_with_json(): void
    {
        /*
         * One test over a table rather than twelve near-identical methods,
         * because the assertion is genuinely the same for all of them and the
         * failure message names the endpoint. Adding a JSON route means adding
         * one line to endpoints() — which is the point: the next JSON endpoint
         * gets covered because covering it is easier than not.
         */
        $failures = [];

        foreach ($this->endpoints() as $label => [$name, $params]) {
            $response = $this->getJson(route($name, $params));

            $type = $response->headers->get('content-type') ?? '';

            if ($response->status() !== 200) {
                $failures[] = "{$label} — HTTP {$response->status()}";

                continue;
            }

            if (! str_contains($type, 'application/json')) {
                $failures[] = "{$label} — content-type was `{$type}`";

                continue;
            }

            // A JSON body that cannot be decoded is a 200 that still breaks
            // every caller, and `assertOk()` alone would not notice.
            json_decode($response->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $failures[] = "{$label} — undecodable body: ".json_last_error_msg();
            }
        }

        $this->assertSame([], $failures, "JSON endpoints that did not answer with JSON:\n"
            .implode("\n", $failures));
    }

    #[Test]
    public function the_product_search_returns_the_fields_the_pos_binds_to(): void
    {
        $response = $this->getJson(route('products.list', [
            'term' => 'Api', 'location_id' => $this->location->id,
        ]));

        $response->assertOk();

        $first = $response->json(0);

        $this->assertNotNull($first, 'The seeded product should be findable by name.');

        // Renaming any of these leaves the endpoint returning 200 and breaks the
        // POS silently, which is the whole reason to name them in a test.
        foreach (['variation_id', 'product_id', 'text', 'sku', 'unit', 'enable_stock', 'qty_available'] as $key) {
            $this->assertArrayHasKey($key, $first, "products.list dropped `{$key}`.");
        }

        $this->assertSame($this->product->id, $first['product_id']);
        $this->assertStringContainsString('Api product', $first['text']);
    }

    #[Test]
    public function the_contact_picker_returns_the_fields_its_callers_bind_to(): void
    {
        $response = $this->getJson(route('contacts.search', ['type' => 'customer', 'term' => 'Api']));

        $response->assertOk();
        $first = $response->json(0);

        $this->assertNotNull($first);

        foreach (['id', 'text', 'mobile', 'balance', 'credit_limit', 'pay_term_number'] as $key) {
            $this->assertArrayHasKey($key, $first, "contacts.search dropped `{$key}`.");
        }

        // The type filter is real: asking for suppliers must not return this one.
        $suppliers = $this->getJson(route('contacts.search', ['type' => 'supplier', 'term' => 'Api customer']));

        $this->assertSame([], $suppliers->json(), 'A customer answered a supplier search.');
    }

    #[Test]
    public function order_lines_offer_only_what_is_still_outstanding(): void
    {
        $response = $this->getJson(route('purchases.orderLines', ['order' => $this->purchaseOrderId]));

        $response->assertOk();
        $line = $response->json(0);

        $this->assertNotNull($line);

        // Cast rather than assertSame against a float literal: `10.0` survives
        // json_encode as `10`, so asserting the decoded *type* tests PHP's JSON
        // precision setting instead of the endpoint.
        $this->assertSame(10.0, (float) $line['ordered']);
        $this->assertSame(0.0, (float) $line['already_invoiced']);

        /*
         * The pre-filled quantity is what a user accepts without reading, so it
         * must never exceed what is left on the order — over-invoicing an order
         * is a real accounting error, not a cosmetic one.
         */
        $this->assertLessThanOrEqual(
            (float) $line['ordered'] - (float) $line['already_invoiced'],
            (float) ($line['quantity'] ?? $line['ordered']),
            'The pre-filled quantity exceeded what is outstanding.'
        );
    }

    #[Test]
    public function outstanding_requisition_lines_exclude_what_is_already_converted(): void
    {
        $response = $this->getJson(route('purchase-requisition.outstandingLines'));

        $response->assertOk();

        $lines = $response->json();
        $this->assertNotEmpty($lines, 'The seeded requisition is still outstanding.');

        foreach ($lines as $line) {
            $this->assertArrayHasKey('purchase_requisition_line_id', $line);
            $this->assertGreaterThan(
                0,
                (float) $line['requested'],
                'A fully converted line should not be offered at all.'
            );
        }
    }

    /* ================================================================
     | Failure modes — the part JavaScript callers actually hit
     ================================================================ */

    #[Test]
    public function a_missing_record_is_a_404_and_not_an_empty_list(): void
    {
        /*
         * An empty array for a deleted order reads to the caller as "that order
         * has no lines", and the form quietly opens with nothing in it. A 404 is
         * what lets the caller say so.
         */
        $this->getJson(route('purchases.orderLines', ['order' => 999999]))->assertNotFound();
        $this->getJson(route('sells.orderLines', ['order' => 999999]))->assertNotFound();
        $this->getJson(route('contacts.due', ['id' => 999999]))->assertNotFound();
    }

    #[Test]
    public function a_missing_required_parameter_is_a_422_with_the_field_named(): void
    {
        $this->getJson(route('products.subUnits'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_id');
    }

    #[Test]
    public function a_guest_gets_a_401_rather_than_a_login_page(): void
    {
        /*
         * The distinction matters to the front end, not to a person: a session
         * that has expired behind an open tab must fail as a status code the
         * caller can branch on. A 302 to /login arrives at `fetch()` as the HTML
         * of the login form, and every picker on the page fails at `.json()`
         * with a parse error that names neither the endpoint nor the cause.
         */
        auth()->logout();
        $this->flushSession();
        // setUp() signed the admin in, and `actingAs()` sets the user on a guard
        // instance that outlives a single request inside one test. Dropping the
        // guards is what actually makes the next request a guest's.
        $this->app['auth']->forgetGuards();

        $this->getJson(route('products.list'))->assertUnauthorized();
        $this->getJson(route('contacts.search'))->assertUnauthorized();
    }

    #[Test]
    public function a_user_without_the_permission_gets_403_as_json(): void
    {
        $role = Role::findOrCreate('Api no-perms #'.uniqid(), 'web');

        $clerk = \App\Models\User::create([
            'user_type' => 'user', 'first_name' => 'Clerk',
            'username' => 'apiclerk_'.uniqid(), 'password' => 'secret-pass',
            'language' => 'ar', 'status' => 'active',
            'business_id' => $this->businessId, 'allow_login' => 1,
        ]);
        $clerk->assignRole($role);

        $this->actingAs($clerk);

        // 403, not 302 and not 500: `permit()` aborts, and the exception handler
        // renders JSON because the request asked for it.
        $this->getJson(route('purchases.orderLines', ['order' => $this->purchaseOrderId]))
            ->assertForbidden();

        $this->getJson(route('labels.products'))->assertForbidden();
    }

    /* ================================================================
     | Product::scopeForLocation() — the scope that took the POS down
     ================================================================ */

    #[Test]
    public function for_location_includes_products_that_are_not_restricted(): void
    {
        /*
         * The scope's own docblock records why this is tested and not eyeballed:
         * the pivot points at `business_locations`, so qualifying the column as
         * `locations.id` is a SQL *error* rather than an empty result. It reached
         * production once and took the POS product search with it, and neither a
         * render walk nor a unit test of the controller would have seen it — the
         * failure is inside a `whereHas` sub-query.
         */
        $found = Product::forLocation($this->location->id)->pluck('id');

        $this->assertContains(
            $this->product->id,
            $found,
            'A product with no location rows is available everywhere.'
        );
    }

    #[Test]
    public function for_location_honours_an_explicit_restriction(): void
    {
        $other = BusinessLocation::create([
            'business_id' => $this->businessId,
            'name' => 'Second store',
            'invoice_scheme_id' => \App\Models\InvoiceScheme::first()->id,
            'invoice_layout_id' => \App\Models\InvoiceLayout::first()->id,
            'is_active' => true,
        ]);

        // Pin the product to the second store only.
        $this->product->product_locations()->sync([$other->id]);

        $this->assertContains(
            $this->product->id,
            Product::forLocation($other->id)->pluck('id'),
            'Restricted to this location, so it must appear here.'
        );

        $this->assertNotContains(
            $this->product->id,
            Product::forLocation($this->location->id)->pluck('id'),
            'Restricted elsewhere, so it must not appear here.'
        );
    }

    #[Test]
    public function for_location_with_no_location_filters_nothing(): void
    {
        // The POS opens before a location is chosen, and an empty result there
        // reads as an empty catalogue.
        $this->assertContains(
            $this->product->id,
            Product::forLocation(null)->pluck('id')
        );
    }

    #[Test]
    public function the_product_search_respects_the_location_it_is_given(): void
    {
        // The scope above, reached the way the POS reaches it — through HTTP,
        // with the sub-query actually executed by MySQL.
        $other = BusinessLocation::create([
            'business_id' => $this->businessId,
            'name' => 'Third store',
            'invoice_scheme_id' => \App\Models\InvoiceScheme::first()->id,
            'invoice_layout_id' => \App\Models\InvoiceLayout::first()->id,
            'is_active' => true,
        ]);

        $this->product->product_locations()->sync([$other->id]);

        $here = $this->getJson(route('products.list', [
            'term' => 'Api', 'location_id' => $this->location->id,
        ]));
        $there = $this->getJson(route('products.list', [
            'term' => 'Api', 'location_id' => $other->id,
        ]));

        $here->assertOk();
        $there->assertOk();

        $this->assertSame([], $here->json(), 'Restricted to the other store.');
        $this->assertNotSame([], $there->json(), 'Restricted to this one.');
    }

    /* ================================================================
     | The manifest — no view, so the render walk skips it
     ================================================================ */

    #[Test]
    public function the_manifest_is_installable(): void
    {
        /*
         * A manifest that 200s but lacks `start_url` or an icon does not fail
         * anywhere visible — the browser simply declines to offer installation,
         * which is the entire point of item 10 shipping it.
         */
        $response = $this->getJson(route('pwa.manifest'));

        $response->assertOk();

        foreach (['name', 'short_name', 'start_url', 'display', 'icons'] as $key) {
            $response->assertJsonStructure([$key]);
        }

        $icons = $response->json('icons');

        $this->assertNotEmpty($icons, 'An installable manifest needs at least one icon.');
        $this->assertSame('192x192', $icons[0]['sizes']);
        $this->assertStringContainsString('icon-192.png', $icons[0]['src']);
    }
}
