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

        ['owner' => $owner, 'business' => $business] = app(BusinessService::class)->register(
            ['name' => 'Screens Co.', 'currency_id' => $currency->id],
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
            }
        }

        $this->assertGreaterThan(15, $checked, 'Expected to walk a meaningful number of screens.');
        $this->assertSame([], $failures, "Screens that did not render:\n".implode("\n", $failures));
    }
}
