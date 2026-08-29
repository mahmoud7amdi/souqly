<?php

namespace Tests\Feature;

use App\Models\Brands;
use App\Models\Category;
use App\Models\Discount;
use App\Models\SellingPriceGroup;
use App\Models\VariationTemplate;
use App\Models\VariationValueTemplate;
use App\Models\Warranty;
use App\Services\BusinessService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Renders every GET screen as an admin and asserts none of them error.
 *
 * This is the cheapest possible guard against a Blade typo, a missing
 * translation key or an undefined variable — it walks the route table, so new
 * screens are covered automatically without adding a test each time.
 */
class ScreensRenderTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Routes that need real request state (a token, a file, a POST body) or
     * that intentionally redirect. Excluded with the reason stated.
     *
     * @var array<string, string>
     */
    private const SKIP = [
        'login' => 'guest-only; covered by ApplicationSmokeTest',
        'api.ping' => 'covered by ApplicationSmokeTest',
        'pwa.manifest' => 'JSON, no view',
        'api.print-queue.pending' => 'needs a print-agent token',
        'status.clear' => 'JSON only',
        'notifications.unreadCount' => 'JSON only',
        'products.list' => 'JSON only',
        'products.subUnits' => 'JSON only',
        'labels.products' => 'JSON only',
        'import-products.template' => 'streams a file download',
        'contacts.import.template' => 'streams a file download',
        'contacts.search' => 'JSON only',
        'contacts.due' => 'JSON only',
        'notifications.show' => 'needs a real notification UUID',
        'storage.local' => 'Laravel built-in file server, not an app screen',
        'purchases.orderLines' => 'JSON only',
        'purchases.supplierOrders' => 'JSON only',
        'purchase-requisition.outstandingLines' => 'JSON only',
        'sells.orderLines' => 'JSON only',
        'sells.customerOrders' => 'JSON only',
        /*
         * The offline snapshot. JSON, and large — walking it would download the
         * whole fixture catalogue on every run to assert nothing the JSON guards
         * above can see. Covered properly in OfflineSyncTest, which is where the
         * things that matter about it live: the location gate, the row shape, and
         * that one variation costs one query rather than two.
         */
        'offline.data' => 'JSON only',
        /*
         * Streams a spreadsheet, and its `{report}` is a slug from
         * ReportController::REPORTS — not an id. resolveParameters() falls back
         * to the fixture product id for any name it does not recognise, so the
         * walk asked for `/reports/627/export` and the controller's whitelist
         * correctly answered 404. Covered properly, one slug at a time, in
         * ReportsTest::every_export_streams_a_spreadsheet.
         */
        'reports.export' => 'streams a file download',
    ];

    private \App\Models\User $admin;

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
         * Every module on, which is the only way this test can do its job.
         *
         * A module-gated screen answers 403 to a tenant that has not enabled its
         * module — `Controller::requireModule()` aborts before the view is ever
         * touched — and the walk reads any status outside [200, 302] as a
         * failure. `register()` turns on two modules by default, so without this
         * the walk over an eight-module ERP was a walk over a quarter of one,
         * and every module screen was invisible to it rather than passing: the
         * first module-gated route to arrive would have turned the suite red
         * while looking like a bug in the screen.
         *
         * Listed literally rather than read from `availableModules()`, which is
         * `protected` on BusinessController. `superadmin` is deliberately absent
         * here because it is deliberately absent there too — see
         * SettingsTest::the_settings_form_rejects_values_that_belong_to_somebody_else.
         */
        ['owner' => $owner, 'business' => $business] = app(BusinessService::class)->register(
            ['name' => 'Screens Co.', 'currency_id' => $currency->id,
                'enabled_modules' => [
                    'purchase_order', 'purchase_requisition', 'sales_order',
                    'inventorymanagement', 'account', 'accounting', 'essentials',
                    'assetmanagement',
                ]],
            ['first_name' => 'Admin', 'username' => 'screens_'.uniqid(),
                'password' => 'secret-pass', 'language' => 'ar']
        );

        $this->admin = $owner;
        \App\Support\Tenancy::bind($business->id);

        $this->seedFixtures();
        \App\Support\Tenancy::forget();
    }

    /**
     * One record per entity, so `edit`/`show` routes have something to bind.
     */
    private function seedFixtures(): void
    {
        Brands::create(['name' => 'Fixture brand', 'created_by' => $this->admin->id]);

        Category::create([
            'name' => 'Fixture category', 'category_type' => 'product',
            'parent_id' => 0, 'created_by' => $this->admin->id,
        ]);

        Warranty::create(['name' => 'Fixture warranty', 'duration' => 12, 'duration_type' => 'months']);

        SellingPriceGroup::create(['name' => 'Fixture group', 'is_active' => 1]);

        \App\Models\CustomerGroup::create([
            'name' => 'Fixture cg', 'amount' => 0,
            'price_calculation_type' => 'percentage', 'created_by' => $this->admin->id,
        ]);

        \App\Models\Contact::create([
            'type' => 'customer', 'name' => 'Fixture contact',
            'first_name' => 'Fixture contact', 'created_by' => $this->admin->id,
            'contact_status' => 'active',
        ]);

        $template = VariationTemplate::create(['name' => 'Size']);
        VariationValueTemplate::create(['variation_template_id' => $template->id, 'name' => 'M']);

        Discount::create([
            'name' => 'Fixture discount', 'discount_type' => 'percentage',
            'discount_amount' => 10, 'is_active' => 1,
        ]);

        $product = $this->createProductFor();
        $this->fixtureProductId = $product->id;
        $this->fixtureVariationId = $product->variations->first()->id;

        $this->seedPurchaseDocuments($product);

        /*
         * Before the sales, and that ordering is the point: the register is open
         * when the fixture sale's cash payment is taken, so the payment listener
         * posts a real drawer row. Seeded afterwards it would post none, and
         * `cash-register.show` would render its empty state while
         * `cash-register.close` showed an expected total of zero — both passing
         * the walk without covering the markup that matters.
         */
        $this->seedFinanceFixtures();
        $this->seedSellDocuments();

        // Last of the documents, because it spends what the purchase and the
        // sales left behind — see the note on quantities in the method itself.
        $this->seedStockDocuments();

        // After the documents, because the inactive branch it adds must not be
        // the one anything was booked against.
        $this->seedSettingsFixtures();

        $this->seedListingDuplicates();

        // Last, and independent of everything above: the ledger's rows need only a
        // location and a signed-in user, and both exist by now.
        $this->seedAccountingFixtures();
    }

    /**
     * The settings area's own rows — the nine screens of item 8.
     *
     * `BusinessService::register()` already leaves one invoice scheme, one
     * invoice layout and one location behind, so those only need a second row
     * here. Printers, barcode presets, extra roles and staff accounts have
     * nothing at all until this runs, which is why their `edit` screens had
     * nothing to bind to.
     *
     * Every pair is deliberate about *which* second row it adds, because the
     * settings screens branch on the difference:
     *
     * - a barcode preset with `business_id IS NULL` is a shared global one, and
     *   the index renders it as a locked "built-in" row rather than an edit icon
     *   ({@see \App\Http\Controllers\BarcodeController::indexViewData()});
     * - a `windows` printer is the kind identified by `path` rather than by an
     *   IP address, so it covers the field the network one leaves empty;
     * - the third location is inactive, which is the only way the index's
     *   inactive badge and the deactivate/activate toggle get exercised;
     * - the extra role is non-default, so the index renders its delete button
     *   and its real permission count instead of Admin's "full access" note.
     */
    private function seedSettingsFixtures(): void
    {
        $business = $this->admin->business_id;

        \App\Models\InvoiceScheme::create([
            'name' => 'Second scheme', 'scheme_type' => 'year', 'number_type' => 'random',
            'prefix' => 'SEC', 'start_number' => 1, 'invoice_count' => 0,
            'total_digits' => 5, 'is_default' => false,
        ]);

        \App\Models\InvoiceLayout::create([
            'name' => 'Second layout', 'design' => 'elegant', 'is_default' => 0,
        ]);

        // Own row first, so `barcodes.edit` binds to something the tenant may
        // actually mutate; findRecord() 404s on the global one below.
        $this->fixtureBarcodeId = \App\Models\Barcode::create([
            'name' => 'Fixture stickers', 'business_id' => $business,
            'width' => 40, 'height' => 20, 'paper_width' => 210, 'paper_height' => 297,
            'top_margin' => 10, 'left_margin' => 10, 'row_distance' => 2, 'col_distance' => 2,
            'stickers_in_one_row' => 4, 'stickers_in_one_sheet' => 40, 'is_default' => true,
        ])->id;

        \App\Models\Barcode::create([
            'name' => 'Global stickers', 'business_id' => null,
            'width' => 50, 'height' => 25, 'stickers_in_one_row' => 3, 'is_default' => false,
        ]);

        $this->fixturePrinterId = \App\Models\Printer::create([
            'name' => 'Counter printer', 'connection_type' => 'network',
            'capability_profile' => 'default', 'char_per_line' => 42,
            'ip_address' => '192.168.1.50', 'port' => '9100',
            'created_by' => $this->admin->id,
        ])->id;

        \App\Models\Printer::create([
            'name' => 'Back-office printer', 'connection_type' => 'windows',
            'capability_profile' => 'simple', 'char_per_line' => 40,
            'path' => '\\\\SERVER\\RECEIPTS', 'created_by' => $this->admin->id,
        ]);

        $first = \App\Models\BusinessLocation::first();

        $this->fixtureLocationId = \App\Models\BusinessLocation::create([
            'business_id' => $business,
            'name' => 'Closed branch',
            'location_id' => app(\App\Services\ReferenceService::class)
                ->generate('business_location', $business),
            'invoice_scheme_id' => $first->invoice_scheme_id,
            'invoice_layout_id' => $first->invoice_layout_id,
            'printer_id' => $this->fixturePrinterId,
            'receipt_printer_type' => 'printer',
            'print_receipt_on_invoice' => 1,
            'is_active' => false,
        ])->id;

        app(BusinessService::class)->createLocationPermission(
            \App\Models\BusinessLocation::find($this->fixtureLocationId)
        );

        $role = \App\Models\Role::create([
            'name' => \App\Models\Role::nameFor('Fixture manager', $business),
            'business_id' => $business, 'is_default' => false, 'guard_name' => 'web',
        ]);
        $role->givePermissionTo(['product.view', 'sell.view', 'purchase.view']);
        $this->fixtureRoleId = $role->id;

        $staff = \App\Models\User::create([
            'user_type' => 'user', 'business_id' => $business,
            'surname' => 'Mr', 'first_name' => 'Fixture', 'last_name' => 'Staff',
            'username' => 'fixture_staff_'.uniqid(), 'password' => 'secret-pass',
            'language' => 'ar', 'status' => 'active', 'allow_login' => 1,
            'is_cmmsn_agnt' => 1, 'cmmsn_percent' => 2.5,
            'max_sales_discount_percent' => 10,
        ]);
        $staff->assignRole($role);
        // Explicit location access rather than access_all_locations: it is the
        // branch of manage_user/_form that ticks individual boxes, and the one
        // an "all locations" fixture would leave uncovered.
        $staff->givePermissionTo(Permission::findOrCreate(
            Permissions::forLocation($first->id), 'web'
        ));
        $this->fixtureStaffId = $staff->id;

        /*
         * Two of the sixteen types, configured differently on purpose: the index
         * shows an automatic/manual badge per channel, and a template with
         * `auto_send` off is the only thing that renders the manual tone. The
         * other fourteen stay unconfigured, which is also a state the index has
         * to draw.
         */
        \App\Models\NotificationTemplate::create([
            'template_for' => 'new_sale', 'subject' => 'Your invoice {invoice_number}',
            'email_body' => 'Thank you, {contact_name}. Total {total_amount}.',
            'sms_body' => 'Invoice {invoice_number}: {total_amount}',
            'auto_send' => true, 'auto_send_sms' => false,
        ]);

        \App\Models\NotificationTemplate::create([
            'template_for' => 'payment_received',
            'subject' => 'Payment received',
            'email_body' => 'We received {received_amount}. Due {due_amount}.',
            'whatsapp_text' => 'Received {received_amount}, thank you.',
            'auto_send' => false, 'auto_send_wa_notif' => true,
        ]);
    }

    /**
     * A SECOND row for every entity that has a listing screen.
     *
     * Not padding. `Builder::hydrate()` arms lazy-load detection per model
     * instance, and only when the query returned more than one row:
     *
     *     if (count($items) > 1) {
     *         $model->preventsLazyLoading = Model::preventsLazyLoading();
     *     }
     *
     * So with one fixture apiece, an index screen that reaches through a
     * relation its controller never eager loaded renders green here and throws
     * LazyLoadingViolationException the moment a real database has two rows.
     * That is exactly how `product.index` shipped reading `$product->variations`
     * with `variations` absent from the controller's `with()` — 100 screens
     * walked clean, then it 500ed on the first seeded catalogue.
     *
     * Two rows is the whole trick: it costs a handful of inserts and turns the
     * walk into a real N+1 / missing-eager-load detector for every screen,
     * including ones written later.
     */
    private function seedListingDuplicates(): void
    {
        Brands::create(['name' => 'Second brand', 'created_by' => $this->admin->id]);

        Category::create([
            'name' => 'Second category', 'category_type' => 'product',
            'parent_id' => 0, 'created_by' => $this->admin->id,
        ]);

        Warranty::create(['name' => 'Second warranty', 'duration' => 6, 'duration_type' => 'months']);

        SellingPriceGroup::create(['name' => 'Second group', 'is_active' => 1]);

        \App\Models\CustomerGroup::create([
            'name' => 'Second cg', 'amount' => 5,
            'price_calculation_type' => 'percentage', 'created_by' => $this->admin->id,
        ]);

        Discount::create([
            'name' => 'Second discount', 'discount_type' => 'fixed',
            'discount_amount' => 5, 'is_active' => 0,
        ]);

        \App\Models\Unit::create([
            'actual_name' => 'Second unit', 'short_name' => 'su',
            'allow_decimal' => 1, 'created_by' => $this->admin->id,
        ]);

        \App\Models\TaxRate::create([
            'name' => 'Second tax', 'calculation_type' => 'percentage',
            'amount' => 5, 'created_by' => $this->admin->id,
        ]);

        $template = VariationTemplate::create(['name' => 'Second template']);
        VariationValueTemplate::create(['variation_template_id' => $template->id, 'name' => 'L']);

        app(\App\Services\AccountService::class)->create([
            'name' => 'Second account',
            'account_number' => 'FIX-ACC-2',
            'opening_balance' => 0,
            'created_by' => $this->admin->id,
        ]);

        // Variable rather than a second single: it gives the product listing two
        // rows AND gives `variations` a multi-row collection of its own, which
        // arms the guard one level deeper.
        $variable = \App\Models\Product::create([
            'name' => 'Second product', 'type' => 'variable',
            'unit_id' => \App\Models\Unit::first()->id, 'tax_type' => 'exclusive',
            'enable_stock' => 1, 'alert_quantity' => 2, 'sku' => 'FIX-2',
            'barcode_type' => 'C128', 'created_by' => $this->admin->id,
        ]);

        app(\App\Services\ProductService::class)->createVariableVariations($variable, [[
            'name' => 'Size',
            'variation_template_id' => VariationTemplate::first()->id,
            'variations' => [
                ['name' => 'M', 'default_purchase_price' => 10, 'default_sell_price' => 18],
                ['name' => 'L', 'default_purchase_price' => 12, 'default_sell_price' => 20],
            ],
        ]]);
    }

    /**
     * A payment account, an expense category with a child, an expense and an
     * open register — the finance side's `show`/`edit` screens all bind by a
     * bare `{id}` and have nothing else to bind to.
     */
    private function seedFinanceFixtures(): void
    {
        $location = \App\Models\BusinessLocation::first();

        /*
         * With an opening balance, so `account.show` renders a real ledger row
         * rather than its empty state. The expense payment below adds a second,
         * mirrored one — the read-only kind, which is separate markup.
         */
        $account = app(\App\Services\AccountService::class)->create([
            'name' => 'Fixture account',
            'account_number' => 'FIX-ACC-1',
            'opening_balance' => 500,
            'created_by' => $this->admin->id,
        ]);

        $category = \App\Models\ExpenseCategory::create([
            'name' => 'Fixture expense category',
            'code' => 'FIX-CAT',
            'business_id' => $this->admin->business_id,
        ]);

        // A child, so the edit form's in-use warning and the sub-category
        // dropdown both have something to show.
        \App\Models\ExpenseCategory::create([
            'name' => 'Fixture expense sub-category',
            'parent_id' => $category->id,
            'business_id' => $this->admin->business_id,
        ]);

        $expense = app(\App\Services\ExpenseService::class)->create([
            'location_id' => $location->id,
            'expense_category_id' => $category->id,
            'expense_for' => $this->admin->id,
            'total_before_tax' => 120,
            'additional_notes' => 'Fixture expense',
            'created_by' => $this->admin->id,
        ], [[
            'amount' => 50,
            'method' => 'bank_transfer',
            'account_id' => $account->id,
            'created_by' => $this->admin->id,
        ]]);

        $register = app(\App\Services\CashRegisterService::class)->open([
            'location_id' => $location->id,
            'user_id' => $this->admin->id,
            'opening_amount' => 100,
        ]);

        /*
         * Part of the expense settled in cash AFTER the register opened, so the
         * drawer holds a `payout` row. Same reasoning as the sale ordering above:
         * without one, `cash-register.show` renders four stat cards instead of
         * five and the close rail's "paid out" line never appears, so the walk
         * would pass over the markup NOTES.md §12.1 added.
         */
        \Illuminate\Support\Facades\DB::transaction(fn () => app(\App\Services\PaymentService::class)
            ->addPayment($expense, [
                'amount' => 30,
                'method' => 'cash',
                'created_by' => $this->admin->id,
            ]));

        $this->fixtureAccountId = $account->id;
        $this->fixtureExpenseCategoryId = $category->id;
        $this->fixtureExpenseId = $expense->id;
        $this->fixtureRegisterId = $register->id;
    }

    /**
     * One purchase, order, requisition and return, so every purchase-side
     * `show`/`edit`/`create` screen has a real document to bind to.
     */
    private function seedPurchaseDocuments(\App\Models\Product $product): void
    {
        $supplier = \App\Models\Contact::create([
            'type' => 'supplier', 'name' => 'Fixture supplier',
            'supplier_business_name' => 'Fixture supplier', 'created_by' => $this->admin->id,
            'contact_status' => 'active',
        ]);

        $location = \App\Models\BusinessLocation::first();
        $purchases = app(\App\Services\PurchaseService::class);

        $header = [
            'location_id' => $location->id,
            'contact_id' => $supplier->id,
            'created_by' => $this->admin->id,
        ];

        $lines = [[
            'variation_id' => $this->fixtureVariationId,
            'quantity' => 10,
            'purchase_price' => 10,
            'purchase_price_inc_tax' => 10,
        ]];

        $purchase = $purchases->create(
            $header + ['status' => \App\Support\TransactionTypes::STATUS_RECEIVED],
            $lines
        );

        $order = $purchases->create($header, $lines, [], \App\Support\TransactionTypes::PURCHASE_ORDER);

        $requisition = $purchases->create(
            $header, $lines, [], \App\Support\TransactionTypes::PURCHASE_REQUISITION
        );

        $return = $purchases->addReturn($purchase, [[
            'purchase_line_id' => $purchase->purchase_lines->first()->id,
            'quantity' => 2,
        ]], ['created_by' => $this->admin->id]);

        $this->fixturePurchaseId = $purchase->id;
        $this->fixtureOrderId = $order->id;
        $this->fixtureRequisitionId = $requisition->id;
        $this->fixtureReturnId = $return->id;
    }

    /**
     * One of each sell-side document, so the sales screens have something to
     * bind to.
     *
     * Five documents rather than one, because the sell side splits its listings
     * by state: `sells.index` shows finals, `sells.drafts` drafts,
     * `sells.quotations` quotations, `sales-order.index` orders and
     * `shipments.index` only sales that carry a shipping status. A single
     * fixture would leave four of those five rendering their empty state, which
     * passes the walk while covering none of the row markup.
     */
    private function seedSellDocuments(): void
    {
        $location = \App\Models\BusinessLocation::first();
        $customer = \App\Models\Contact::where('type', 'customer')->first();
        $sells = app(\App\Services\SellService::class);

        $header = [
            'location_id' => $location->id,
            'contact_id' => $customer->id,
            'created_by' => $this->admin->id,
        ];

        $lines = [[
            'variation_id' => $this->fixtureVariationId,
            'quantity' => 2,
            'unit_price' => 15,
            'unit_price_inc_tax' => 15,
        ]];

        /*
         * Shipped and part-paid on purpose: it is the only fixture that reaches
         * shipments.index, and a sale with no payment_lines would never exercise
         * the payment table on sell.show.
         */
        $sale = $sells->create(
            $header + [
                'status' => \App\Support\TransactionTypes::STATUS_FINAL,
                'shipping_status' => 'packed',
                'delivered_to' => 'Fixture recipient',
                'delivery_person' => $this->admin->id,
            ],
            $lines,
            [['amount' => 20, 'method' => 'cash', 'created_by' => $this->admin->id]]
        );

        /*
         * Not captured: nothing binds to a draft or a quotation by id — both are
         * reached through their listing, and `sells.show`/`sells.edit` are
         * exercised by the final sale above.
         */
        $sells->create(
            $header + ['status' => \App\Support\TransactionTypes::STATUS_DRAFT],
            $lines
        );

        $sells->create(
            $header + [
                'status' => \App\Support\TransactionTypes::STATUS_DRAFT,
                'is_quotation' => true,
            ],
            $lines
        );

        $salesOrder = $sells->create(
            $header, $lines, [], \App\Support\TransactionTypes::SALES_ORDER
        );

        $return = $sells->addReturn($sale, [[
            'sell_line_id' => $sale->sell_lines->first()->id,
            'quantity' => 1,
        ]], ['created_by' => $this->admin->id]);

        $this->fixtureSellId = $sale->id;
        $this->fixtureSalesOrderId = $salesOrder->id;
        $this->fixtureSellReturnId = $return->id;

        /*
         * The sale's own cash payment, reused rather than a payment created just
         * for the payment screens: it is a plain parentless non-advance row, so
         * `payments.edit` renders the form instead of bouncing to `show`, and it
         * is the row the open register above has just mirrored.
         */
        $this->fixturePaymentId = $sale->payment_lines()->first()->id;
    }

    /**
     * A second shop, an opening stock statement, one adjustment and one transfer
     * still on the road.
     *
     * The second location is the reason this method exists at all: a transfer is
     * the only document in the app that cannot be written with one shop, and
     * every other fixture here is happy with `BusinessLocation::first()`. It
     * also gives every location dropdown on every screen a second option, which
     * is the only way the "pick two different ones" markup gets exercised.
     *
     * In transit rather than completed on purpose: it is the state that carries
     * extra markup nothing else covers — the receive button, the in-transit
     * banner, the pending-receipt wording and the warning-toned stat.
     */
    private function seedStockDocuments(): void
    {
        $from = \App\Models\BusinessLocation::first();

        $to = \App\Models\BusinessLocation::create([
            'business_id' => $this->admin->business_id,
            'name' => 'Second shop',
            'location_id' => app(\App\Services\ReferenceService::class)
                ->generate('business_location', $this->admin->business_id),
            'invoice_scheme_id' => $from->invoice_scheme_id,
            'invoice_layout_id' => $from->invoice_layout_id,
            'is_active' => true,
            'receipt_printer_type' => 'browser',
            'print_receipt_on_invoice' => 1,
        ]);

        // Its own permission, because an admin bypasses the location gate and
        // would hide a missing one: a restricted role reads these dropdowns
        // through `location.{id}`, and a location without that permission is
        // invisible to everyone but the owner.
        app(BusinessService::class)->createLocationPermission($to);

        /*
         * Quantities are deliberately small. The stock these documents spend is
         * what the purchase above left after its return and the sales — so they
         * are sized to fit inside that with room to spare, rather than being
         * silently coupled to those figures. The opening statement runs first
         * and adds five more, which is also what gives `opening-stock.edit` its
         * recorded path, its `already_used` column and its position panel.
         */
        app(\App\Services\OpeningStockService::class)->save(
            \App\Models\Product::find($this->fixtureProductId),
            $from->id,
            [$this->fixtureVariationId => 5],
            [$this->fixtureVariationId => 9],
            null,
            $this->admin->id,
        );

        // Abnormal with a recovery amount: `stock-adjustments.index` totals the
        // abnormal ones separately and shows what was recovered, so a normal
        // adjustment of zero would leave both figures at nought.
        $adjustment = app(\App\Services\StockAdjustmentService::class)->create([
            'location_id' => $from->id,
            'adjustment_type' => 'abnormal',
            'total_amount_recovered' => 4,
            'additional_notes' => 'Fixture adjustment',
            'created_by' => $this->admin->id,
        ], [[
            'variation_id' => $this->fixtureVariationId,
            'quantity' => 1,
        ]]);

        $transfer = app(\App\Services\StockTransferService::class)->create([
            'location_id' => $from->id,
            'transfer_location_id' => $to->id,
            'status' => \App\Support\TransactionTypes::STATUS_IN_TRANSIT,
            'shipping_charges' => 15,
            'shipping_details' => 'Fixture van',
            'created_by' => $this->admin->id,
        ], [[
            'variation_id' => $this->fixtureVariationId,
            'quantity' => 2,
        ]]);

        $this->fixtureAdjustmentId = $adjustment->id;
        $this->fixtureTransferId = $transfer->id;

        /*
         * An open stock count carrying one line.
         *
         * Without it `inventory.show` and `inventory.edit` fall through to the
         * walk's default fixture-product id, `findCount()` calls `findOrFail()`
         * on it, and the pair answer 404 — a red walk describing a missing
         * fixture rather than a broken screen.
         *
         * Open rather than closed, deliberately: `edit()` redirects a closed
         * count to `show`, and 302 is inside the statuses the walk accepts, so a
         * closed fixture would let the edit screen pass without ever being
         * rendered.
         *
         * The line is counted below book on purpose, so `show` has a real
         * shortage for its summary panel and its difference column instead of the
         * designed empty state — which is otherwise the only branch a bare render
         * would ever reach.
         */
        $count = app(\App\Services\InventoryCountService::class)->create([
            'branch_id' => $from->id,
            'name' => 'Fixture count',
            'end_date' => now()->addWeek()->toDateString(),
        ]);

        app(\App\Services\InventoryCountService::class)->countLine(
            $count, $this->fixtureVariationId, 1
        );

        $this->fixtureCountId = $count->id;

        /*
         * An asset with something out on it, and one maintenance job.
         *
         * The allocation is the reason this is not a two-line fixture. `asset.show`
         * has two mutually exclusive halves — the hand-over form when something is
         * available, the allocation table when something is out — and a bare asset
         * would render only the first. Allocating part of the quantity puts the
         * screen in the state where both are visible at once, which is the branch
         * with the outstanding column, the return form, and the overdue badge in it.
         *
         * `allocated_upto` is in the past on purpose: the overdue badge is the one
         * row state a fixture can reach for free, and an untranslated key inside it
         * would otherwise never be walked.
         *
         * Signed in first, unlike every other fixture here, because `AssetService`
         * reads `created_by` from `auth()` rather than taking it as data — and
         * `assets.created_by` is `NOT NULL` behind a foreign key, so a guest seed
         * fails on the insert rather than storing a null.
         */
        $this->actingAs($this->admin);

        $asset = app(\App\Services\AssetService::class)->create([
            'name' => 'Fixture asset',
            'quantity' => 4,
            'unit_price' => 1000,
            'purchase_date' => now()->subYear()->toDateString(),
            'purchase_type' => 'new',
            'depreciation' => 10,
            'location_id' => $from->id,
            'is_allocatable' => true,
        ]);

        app(\App\Services\AssetService::class)->allocate($asset, [
            'receiver' => $this->admin->id,
            'quantity' => 1,
            'allocated_upto' => now()->subDay()->toDateString(),
        ]);

        $maintenance = \App\Modules\AssetManagement\Models\AssetMaintenance::create([
            'business_id' => $asset->business_id,
            'asset_id' => $asset->id,
            'maitenance_id' => 'FIXTURE-MNT-1',
            'status' => 'scheduled',
            'priority' => 'high',
            'details' => 'Fixture fault',
            'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        $this->fixtureAssetId = $asset->id;
        $this->fixtureMaintenanceId = $maintenance->id;
    }

    /**
     * The accounting module's own rows — the sixteen screens of item 11's ledger.
     *
     * Runs last and needs nothing the other seeders leave behind, so the placement
     * is free; it is last only because it is newest.
     *
     * `actingAs` here rather than in the walk, because two of these writes read the
     * authenticated user directly: `postJournal()` stamps `created_by_id` from
     * `auth()->id()` and `transfer()` stamps `transfer_by_id` the same way. Seeded
     * unauthenticated they would both store 0, and the "posted by" and
     * "transferred by" cells would render their em-dash fallback — passing the walk
     * while covering the wrong branch of exactly the markup this seeder exists to
     * reach.
     */
    private function seedAccountingFixtures(): void
    {
        $this->actingAs($this->admin);

        $accounting = app(\App\Services\AccountingService::class);
        $locationId = \App\Models\BusinessLocation::first()->id;

        /*
         * Five accounts, each earning its place on a screen rather than padding the
         * chart:
         *
         * - `cash` and `bank` are both debit-natured with an opening balance, which
         *   is what gives the trial balance a non-zero opening column and the
         *   transfer two ends;
         * - `sales` is credit-natured, so the opening column has to net the two
         *   signs against each other — the bug `openingAsDebit()` exists to prevent
         *   would show up here as an opening total equal to the sum of the three;
         * - `petty` is a child of `cash`, so the chart's parent meta and the
         *   `wouldCycle` guard both have a tree to walk;
         * - `old` is archived, which is the only way the index's archived badge and
         *   its `state` filter get exercised at all.
         */
        $cash = $accounting->createAccount([
            'name' => 'Fixture cash', 'gl_code' => 1000, 'account_type' => 'asset',
            'opening_balance' => '5000', 'allow_manual' => true,
        ]);

        $bank = $accounting->createAccount([
            'name' => 'Fixture bank', 'gl_code' => 1010, 'account_type' => 'asset',
            'opening_balance' => '20000', 'allow_manual' => true,
        ]);

        $sales = $accounting->createAccount([
            'name' => 'Fixture sales', 'gl_code' => 4000, 'account_type' => 'income',
            'opening_balance' => '25000', 'allow_manual' => true,
        ]);

        $accounting->createAccount([
            'name' => 'Fixture petty cash', 'gl_code' => 1001, 'account_type' => 'asset',
            'parent_id' => $cash->id, 'allow_manual' => true,
            'notes' => 'A child, so the chart has a tree to render.',
        ]);

        $accounting->createAccount([
            'name' => 'Fixture closed account', 'gl_code' => 1099,
            'account_type' => 'asset', 'active' => false,
        ]);

        /*
         * Two cost centres, and the difference between them is the point. The first
         * carries a journal line, so the listing renders its entries link and hides
         * its delete button. The second carries none and is inactive with a parent,
         * a manager and a budget — so the archived badge, the parent meta, the
         * manager cell and the delete form all render on the same walk.
         */
        $operations = $accounting->createCostCenter([
            'code' => 'CC-100', 'name' => 'Fixture operations', 'type' => 'cost',
            'manager_id' => $this->admin->id, 'location_id' => $locationId,
            'budget_amount' => '15000', 'budget_period' => 'monthly', 'sort_order' => 1,
        ]);

        $accounting->createCostCenter([
            'code' => 'CC-200', 'name' => 'Fixture marketing', 'type' => 'profit',
            'parent_id' => $operations->id, 'manager_id' => $this->admin->id,
            'budget_amount' => '2500', 'budget_period' => 'yearly',
            'is_active' => false, 'sort_order' => 2,
        ]);

        /*
         * The document the `{number}` routes bind to. Deliberately the *live* one:
         * `showJournal()` builds `canReverse` from the permission and the two
         * conditions the service refuses on, so binding the walk to a reversed
         * document would render the screen with its reverse button suppressed and
         * never touch that markup.
         */
        $this->fixtureJournalNumber = $accounting->postJournal([
            'date' => now()->toDateString(),
            'name' => 'Fixture journal',
            'reference' => 'FIX-001',
            'notes' => 'Seeded so the journal has a document to show.',
            'location_id' => $locationId,
            'lines' => [
                ['chart_of_account_id' => $cash->id, 'debit' => 750, 'credit' => 0,
                    'cost_center_id' => $operations->id, 'notes' => 'Cash in'],
                ['chart_of_account_id' => $sales->id, 'debit' => 0, 'credit' => 750,
                    'cost_center_id' => $operations->id],
            ],
        ]);

        // A second document, then reversed — which is what puts a reversed row and
        // its mirror on the listing, and the only way the reversed badge is seen.
        $accounting->reverse($accounting->postJournal([
            'date' => now()->toDateString(),
            'name' => 'Fixture journal to reverse',
            'lines' => [
                ['chart_of_account_id' => $cash->id, 'debit' => 120, 'credit' => 0],
                ['chart_of_account_id' => $sales->id, 'debit' => 0, 'credit' => 120],
            ],
        ]));

        // Posts a third document of its own, sub-typed `transfer`, so both the
        // transfers listing and the journal's transfer badge have a row.
        $accounting->transfer([
            'transfer_from_id' => $cash->id,
            'transfer_to_id' => $bank->id,
            'amount' => '400',
            'date' => now()->toDateString(),
            'location_id' => $locationId,
            'notes' => 'Seeded transfer.',
        ]);

        $this->fixtureChartAccountId = $cash->id;
        $this->fixtureCostCenterId = $operations->id;
    }

    private int $fixtureProductId;

    private int $fixtureVariationId;

    private int $fixturePurchaseId;

    private int $fixtureOrderId;

    private int $fixtureRequisitionId;

    private int $fixtureReturnId;

    private int $fixtureSellId;

    private int $fixtureSalesOrderId;

    private int $fixtureSellReturnId;

    private int $fixturePaymentId;

    private int $fixtureAccountId;

    private int $fixtureExpenseCategoryId;

    private int $fixtureExpenseId;

    private int $fixtureRegisterId;

    private int $fixtureAdjustmentId;

    private int $fixtureTransferId;

    private int $fixtureCountId;

    private int $fixtureAssetId;

    private int $fixtureMaintenanceId;

    private int $fixtureBarcodeId;

    private int $fixturePrinterId;

    private int $fixtureLocationId;

    private int $fixtureRoleId;

    private int $fixtureStaffId;

    private int $fixtureChartAccountId;

    private int $fixtureCostCenterId;

    /* A string, not an int, unlike every other fixture here: the journal's document
       routes bind a `{number}` — the reference the service generates — because a
       document is the set of rows sharing a `transaction_number`, and there is no
       single key to bind to. */
    private string $fixtureJournalNumber;

    private function createProductFor(): \App\Models\Product
    {
        $unit = \App\Models\Unit::first();

        $product = \App\Models\Product::create([
            'name' => 'Fixture product', 'type' => 'single', 'unit_id' => $unit->id,
            'tax_type' => 'exclusive', 'enable_stock' => 1, 'alert_quantity' => 5,
            'sku' => 'FIX-1', 'barcode_type' => 'C128', 'created_by' => $this->admin->id,
        ]);

        app(\App\Services\ProductService::class)->createSingleVariation($product, [
            'default_purchase_price' => 10, 'default_sell_price' => 15,
        ]);

        return $product->fresh('variations');
    }

    /**
     * Substitute a real id for each route parameter.
     *
     * Routes that use a bare `{id}` are resolved by route name, since the
     * parameter name alone doesn't say which entity it belongs to.
     */
    private function resolveParameters(\Illuminate\Routing\Route $route): array
    {
        $byRouteName = [
            'selling-price-group.toggle' => SellingPriceGroup::class,
            'discount.toggle' => Discount::class,
            'products.activate' => \App\Models\Product::class,
            'products.addSellingPrices' => \App\Models\Product::class,
            'products.stockHistory' => \App\Models\Product::class,
            'contacts.ledger' => \App\Models\Contact::class,
            'contacts.status' => \App\Models\Contact::class,
            'contacts.openingBalance.edit' => \App\Models\Contact::class,
        ];

        /*
         * Bare `{id}` routes, keyed by route name, because the parameter name
         * says nothing about which entity it belongs to. Ids rather than model
         * classes wherever `Model::first()` would pick the wrong row — the
         * payment screens want the sale's payment, not the expense's.
         */
        $idByRouteName = [
            'purchase-order.pdf' => $this->fixtureOrderId,
            'purchase-return.show' => $this->fixtureReturnId,
            'sell-return.show' => $this->fixtureSellReturnId,
            'payments.show' => $this->fixturePaymentId,
            'payments.edit' => $this->fixturePaymentId,
            'expenses.show' => $this->fixtureExpenseId,
            'expenses.edit' => $this->fixtureExpenseId,
            'expense-categories.edit' => $this->fixtureExpenseCategoryId,
            'accounts.show' => $this->fixtureAccountId,
            'accounts.edit' => $this->fixtureAccountId,
            'cash-register.show' => $this->fixtureRegisterId,
            'cash-register.closeForm' => $this->fixtureRegisterId,
            'stock-adjustments.show' => $this->fixtureAdjustmentId,
            'stock-adjustments.edit' => $this->fixtureAdjustmentId,
            'stock-transfers.show' => $this->fixtureTransferId,
            // The open count seeded above, not a product id: `findCount()`
            // `findOrFail()`s, so the default fallback would 404 both screens.
            'inventory.show' => $this->fixtureCountId,
            'inventory.edit' => $this->fixtureCountId,
            // Same reason as the counts above: both controllers resolve the record
            // through a scoped `findOrFail()`, so a product id would 404 all three.
            'assets.show' => $this->fixtureAssetId,
            'assets.edit' => $this->fixtureAssetId,
            'asset-maintenance.edit' => $this->fixtureMaintenanceId,
            /*
             * The accounting chart and its cost centres. All three resolve the row
             * through a `forBusiness()` `findOrFail()`, so the default
             * fixture-product id would 404 them rather than render them.
             *
             * `accounts.show` / `accounts.edit` above belong to the cash-and-bank
             * module — a different screen on a different table. The two pairs do not
             * collide because these carry the `accounting.` prefix.
             */
            'accounting.accounts.show' => $this->fixtureChartAccountId,
            'accounting.accounts.edit' => $this->fixtureChartAccountId,
            'accounting.cost-centers.edit' => $this->fixtureCostCenterId,
            /*
             * The three GET print routes. Walked rather than skipped, exactly as
             * `purchase-order.pdf` is: they are the whole point of item 9, they
             * render Blade, and the untranslated-key guard is worth more on them
             * than on most screens — a print view is the one page in the app a
             * customer reads, and `invoice_layouts` supplies ninety label
             * overrides for it. `print.enqueue` is a POST and drops out of the
             * walk on its own; it is covered in PrintingTest.
             */
            'print.invoice' => $this->fixtureSellId,
            'print.pdf' => $this->fixtureSellId,
            'print.receipt' => $this->fixtureSellId,
            // The inactive branch, so the toggle flips a location nothing was
            // booked against rather than deactivating the shop mid-walk.
            'business-location.toggle' => $this->fixtureLocationId,
        ];

        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            if ($name === 'id' && isset($idByRouteName[$route->getName()])) {
                $parameters[$name] = $idByRouteName[$route->getName()];

                continue;
            }

            if ($name === 'id' && isset($byRouteName[$route->getName()])) {
                $parameters[$name] = $byRouteName[$route->getName()]::first()->id;

                continue;
            }

            $parameters[$name] = match ($name) {
                'variation' => $this->fixtureVariationId,
                'product' => $this->fixtureProductId,
                'brand' => Brands::first()->id,
                'unit' => \App\Models\Unit::first()->id,
                'tax_rate' => \App\Models\TaxRate::first()->id,
                'warranty' => Warranty::first()->id,
                'taxonomy' => Category::first()->id,
                'variation_template' => VariationTemplate::first()->id,
                'selling_price_group' => SellingPriceGroup::first()->id,
                'discount' => Discount::first()->id,
                'contact' => \App\Models\Contact::first()->id,
                'customer_group' => \App\Models\CustomerGroup::first()?->id ?? 1,
                'purchase' => $this->fixturePurchaseId,
                'purchase_order', 'order' => $this->fixtureOrderId,
                'purchase_requisition' => $this->fixtureRequisitionId,
                'sell' => $this->fixtureSellId,
                'sales_order' => $this->fixtureSalesOrderId,

                /*
                 * Settings. Each resource route names its parameter after the
                 * singular resource, and every one of these needs an id the
                 * *controller* will accept, not merely one that exists:
                 * `barcode` must be the tenant's own preset because
                 * BarcodeController::findRecord() 404s on a global one, and
                 * `template` is a `template_for` slug rather than an id at all —
                 * NotificationTemplateController aborts 404 on anything outside
                 * NotificationTemplate::templateTypes(), which is what the
                 * default fixture-id fallback below was handing it.
                 */
                'invoice_scheme' => \App\Models\InvoiceScheme::first()->id,
                'invoice_layout' => \App\Models\InvoiceLayout::first()->id,
                'barcode' => $this->fixtureBarcodeId,
                'printer' => $this->fixturePrinterId,
                'business_location' => \App\Models\BusinessLocation::first()->id,
                'role' => $this->fixtureRoleId,
                'user' => $this->fixtureStaffId,
                'template' => 'new_sale',

                /*
                 * The journal's document routes take a `{number}`, not an id, for the
                 * reason recorded on the property: there is no header row to key off.
                 * Left to the default below, `showJournal()` would be handed a
                 * product id as a transaction reference and answer 404.
                 */
                'number' => $this->fixtureJournalNumber,

                default => $this->fixtureProductId,
            };
        }

        return $parameters;
    }

    #[Test]
    public function every_get_screen_renders_without_error(): void
    {
        $this->actingAs($this->admin);

        $checked = 0;
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (empty($name)
                || ! in_array('GET', $route->methods(), true)
                || array_key_exists($name, static::SKIP)
                || str_starts_with($route->uri(), 'api/')
                || str_starts_with($route->uri(), '_')) {
                continue;
            }

            try {
                $url = route($name, $this->resolveParameters($route));
            } catch (\Throwable $e) {
                $failures[] = $name.' — could not build URL: '.$e->getMessage();

                continue;
            }

            $response = $this->get($url);
            $checked++;

            // 200 = rendered, 302 = a legitimate redirect (e.g. toggles).
            if (! in_array($response->status(), [200, 302], true)) {
                $failures[] = sprintf(
                    '%s (%s) → HTTP %d',
                    $name,
                    $url,
                    $response->status()
                );

                continue;
            }

            /*
             * A missing translation key renders as the key itself — "lang_v1.foo"
             * in the middle of the page. That is invisible to a status-code check
             * but glaring to a user, and it is the single most common defect when
             * a screen is written before its keys are added. Catching it here is
             * why the guard lives in the walk rather than in a per-screen test.
             */
            if ($response->status() === 200) {
                $body = $response->getContent();

                if (preg_match_all('/lang_v1\.[a-z0-9_.]+/i', $body, $matches)) {
                    $failures[] = sprintf(
                        '%s (%s) → untranslated keys: %s',
                        $name,
                        $url,
                        implode(', ', array_unique($matches[0]))
                    );
                }

                /*
                 * Unbalanced divs, which is how a layout breaks without anything
                 * failing. The POS cart lost its wrapping `<div>` to a Blade comment
                 * whose terminator had a space in it (`--------- }}` rather than
                 * `--------- --}}`), so the compiler ran the comment on to the next
                 * real `--}}` and deleted the line between. The page still returned
                 * 200 with every translation present; the only symptom was the cart
                 * scattering itself across the terminal's grid cells.
                 *
                 * Scripts are cut out first: a `'<div>'` inside a JS string is not
                 * markup, and counting it would make this guard cry wolf on any
                 * screen that builds HTML in JavaScript.
                 */
                $markup = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $body);
                $open = preg_match_all('/<div\b/i', $markup);
                $close = substr_count($markup, '</div>');

                if ($open !== $close) {
                    $failures[] = sprintf(
                        '%s (%s) → %d <div> vs %d </div> (%+d): markup is unbalanced',
                        $name,
                        $url,
                        $open,
                        $close,
                        $open - $close
                    );
                }

                /*
                 * Headings with no text. `<x-panel>` renders its header whenever it
                 * has a title, an icon *or* an actions slot, so a panel given only
                 * actions emits `<h3 class="card-title"><span></span></h3>` — a blank
                 * line on screen and a heading a screen reader announces with nothing
                 * to say. §11.4's grouping work makes this easy to hit: moving a
                 * record count or a search box out of a card header and into a
                 * `.section-head` leaves the panel titleless but still slotted.
                 *
                 * The check reads text content rather than the tag, because the defect
                 * is an empty *name*: an icon-only header is just as silent as an
                 * empty one, and both look like a rendering fault.
                 */
                foreach (['h1', 'h2', 'h3'] as $tag) {
                    preg_match_all("#<{$tag}\b[^>]*>(.*?)</{$tag}>#is", $markup, $headings);

                    foreach ($headings[1] as $inner) {
                        /* "\xC2\xA0" is a decoded &nbsp;. PHP's default trim charlist
                           is single-byte, so it leaves that pair in place and a
                           heading padded out with &nbsp; would read as non-empty. */
                        $text = trim(
                            html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5),
                            " \t\n\r\0\x0B\xC2\xA0"
                        );

                        if ($text === '') {
                            $failures[] = sprintf(
                                '%s (%s) → empty <%s>: a heading with no text',
                                $name,
                                $url,
                                $tag
                            );
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(15, $checked, 'Expected to walk a meaningful number of screens.');
        $this->assertSame([], $failures, "Screens that did not render:\n".implode("\n", $failures));
    }

    /**
     * The till's left column is one connected unit, not four loose pieces.
     *
     * `.pos-shell` is a two-column grid, so anything that escapes the cart wrapper
     * becomes a grid item in its own right and gets placed in the next free cell —
     * the counter in one, the rows in another, the total somewhere below. The
     * cashier is then reading a scattered cart while serving a customer.
     *
     * Containment is asserted through the DOM rather than by searching the HTML,
     * because the bug this guards against does not remove the four parts; it moves
     * them out of their parent, which a substring check cannot see.
     */
    #[Test]
    public function the_pos_cart_is_one_connected_column(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get(route('pos.create'));
        $response->assertOk();

        $dom = new \DOMDocument;
        $dom->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        // Whole-word class match, so `pos-cart-scroll` is not mistaken for it.
        $cart = $xpath->query(
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' pos-cart ')]"
        );

        $this->assertSame(1, $cart->length, 'the cart column wrapper is not in the rendered page');

        /*
         * In this order, top to bottom: what is in the cart, the cart itself, what
         * it comes to, and the button that takes the money. The order is the screen's
         * whole argument — a total above the lines it totals reads as a price.
         */
        $parts = ['cart-count', 'cart-rows', 'cart-total', 'open-payment'];

        foreach ($parts as $id) {
            $this->assertSame(
                1,
                $xpath->query(".//*[@id='{$id}']", $cart->item(0))->length,
                "#{$id} is not inside the cart column"
            );
        }

        $column = $dom->saveHTML($cart->item(0));

        $positions = array_map(fn ($id) => strpos($column, 'id="'.$id.'"'), $parts);
        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions, 'the cart column is in the wrong order: '.implode(' → ', $parts));
    }
}
