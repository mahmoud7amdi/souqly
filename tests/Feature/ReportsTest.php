<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ExpenseCategory;
use App\Models\Transaction;
use App\Models\TransactionSellLine;
use App\Models\Variation;
use App\Services\BusinessService;
use App\Services\ExpenseService;
use App\Services\PurchaseService;
use App\Services\ReportService;
use App\Services\SellService;
use App\Support\Permissions;
use App\Support\TransactionTypes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The arithmetic behind the reports, and the gates in front of them.
 *
 * {@see ScreensRenderTest} already proves every report *renders*. What it cannot
 * see is whether the numbers on it are right: a report that shows a confident,
 * well-formatted, wrong profit passes a rendering walk perfectly. So this class
 * asserts figures, not markup — and asserts them against documents put through
 * the real services, never against rows hand-inserted to match the expectation.
 * Hand-built fixtures would only prove the report agrees with the fixture.
 *
 * The costing assertions are the point of the file. Gross profit is
 * revenue − FIFO cost, and every term in that comes from a different table:
 * revenue from the sell lines, cost from the map into `purchase_lines`. Nothing
 * about a wrong join there is visible on screen — it just prints a number that
 * is quietly too big, on the one report an owner actually makes decisions with.
 */
class ReportsTest extends TestCase
{
    use DatabaseTransactions;

    private ReportService $reports;

    private PurchaseService $purchases;

    private SellService $sells;

    /** The whole of the current month — what a report defaults to. */
    private array $range;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = app(ReportService::class);
        $this->purchases = app(PurchaseService::class);
        $this->sells = app(SellService::class);

        $this->createTenant();

        /*
         * A report is always read by somebody. Every report query passes through
         * `permittedLocations()`, which resolves against `auth()->user()` — so
         * with nobody signed in it returns an empty id list, the scope filters
         * out every row, and each figure below comes back a confident 0.0. The
         * assertions would then be passing against an empty report rather than
         * against the arithmetic, which is the exact failure this file exists to
         * catch. Signing in as the owner, holding the permission an owner really
         * holds, is what makes these tests measure anything at all.
         */
        Permission::findOrCreate('access_all_locations', 'web');
        $this->user->givePermissionTo('access_all_locations');
        $this->actingAs($this->user);

        $this->range = [
            'start' => now()->startOfMonth()->toDateString(),
            'end' => now()->endOfMonth()->toDateString(),
        ];
    }

    /* ================================================================
     | Fixtures — real documents through the real services
     ================================================================ */

    private function supplier(): Contact
    {
        return Contact::create([
            'type' => 'supplier', 'name' => 'Report supplier',
            'first_name' => 'Report supplier', 'contact_status' => 'active',
            'created_by' => $this->user->id,
        ]);
    }

    private function customer(): Contact
    {
        return Contact::create([
            'type' => 'customer', 'name' => 'Report customer',
            'first_name' => 'Report customer', 'contact_status' => 'active',
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * A received purchase — the lot every sale below is costed against.
     *
     * Tax is passed as a `tax_id`, never as a `tax_amount`: both services
     * recompute `tax_amount` from the rate in `recalculateTotals()`, so a
     * hand-set amount is silently discarded and the tax assertions would be
     * testing zero against zero.
     */
    private function buy(Variation $variation, float $qty, float $unitCost, ?int $taxId = null): Transaction
    {
        return $this->purchases->create(
            [
                'location_id' => $this->location->id,
                'contact_id' => $this->supplier()->id,
                'status' => TransactionTypes::STATUS_RECEIVED,
                'transaction_date' => now()->toDateTimeString(),
                'created_by' => $this->user->id,
                'tax_id' => $taxId,
            ],
            [[
                'product_id' => $variation->product_id,
                'variation_id' => $variation->id,
                'quantity' => $qty,
                'purchase_price' => $unitCost,
                'purchase_price_inc_tax' => $unitCost,
            ]]
        );
    }

    private function sell(Variation $variation, float $qty, float $unitPrice, ?int $taxId = null): Transaction
    {
        return $this->sells->create(
            [
                'location_id' => $this->location->id,
                'contact_id' => $this->customer()->id,
                'status' => TransactionTypes::STATUS_FINAL,
                'transaction_date' => now()->toDateTimeString(),
                'created_by' => $this->user->id,
                'tax_id' => $taxId,
            ],
            [[
                'product_id' => $variation->product_id,
                'variation_id' => $variation->id,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'unit_price_inc_tax' => $unitPrice,
            ]]
        );
    }

    /**
     * `total_before_tax` rather than `final_total`: ExpenseService derives the
     * total from the net plus whatever the tax rate adds.
     */
    private function spend(float $amount): Transaction
    {
        $category = ExpenseCategory::create([
            'name' => 'Report category '.uniqid(),
            'business_id' => $this->business->id,
        ]);

        return app(ExpenseService::class)->create([
            'location_id' => $this->location->id,
            'expense_category_id' => $category->id,
            'transaction_date' => now()->toDateTimeString(),
            'total_before_tax' => $amount,
            'created_by' => $this->user->id,
        ]);
    }

    /* ================================================================
     | Profit & loss — the figures nothing else checks
     ================================================================ */

    #[Test]
    public function gross_profit_is_revenue_minus_what_those_units_actually_cost(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        // Two lots at different costs, so an average-cost mistake and a FIFO
        // reading produce different answers. At one price they would agree, and
        // the test would pass either way.
        $this->buy($variation, 10, 5.00);
        $this->buy($variation, 10, 8.00);

        // 15 units: all of lot 1 at 5, then 5 of lot 2 at 8 → 50 + 40 = 90.
        $this->sell($variation, 15, 20.00);

        $pl = $this->reports->profitLoss($this->range);

        $this->assertSame(300.0, $pl['sales']['net'], '15 x 20');
        $this->assertSame(90.0, $pl['cogs']['net'], 'FIFO: (10 x 5) + (5 x 8)');
        $this->assertSame(210.0, $pl['gross_profit']);

        // Not 15 x average(5,8) = 97.5 — the number an average-cost join gives.
        $this->assertNotSame(97.5, $pl['cogs']['net']);

        $this->assertSame(
            round($pl['sales']['net'] - $pl['cogs']['net'], 4),
            $pl['gross_profit'],
            'Gross profit must equal net sales minus net cost, by definition.'
        );
    }

    #[Test]
    public function a_return_reduces_both_the_revenue_and_the_cost(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->buy($variation, 10, 5.00);
        $sale = $this->sell($variation, 10, 20.00);

        $before = $this->reports->profitLoss($this->range);
        $this->assertSame(200.0, $before['sales']['net']);
        $this->assertSame(50.0, $before['cogs']['net']);
        $this->assertSame(150.0, $before['gross_profit']);

        $this->sells->addReturn($sale, [[
            'sell_line_id' => $sale->sell_lines->first()->id,
            'quantity' => 4,
        ]], ['created_by' => $this->user->id]);

        $after = $this->reports->profitLoss($this->range);

        // Both sides move, and that is the whole assertion. Crediting the
        // revenue while leaving the cost behind is the classic returns bug: it
        // reports a loss on goods that are back on the shelf.
        $this->assertSame(80.0, $after['sales']['returned'], '4 x 20');
        $this->assertSame(20.0, $after['cogs']['returned'], '4 x 5');

        $this->assertSame(120.0, $after['sales']['net']);
        $this->assertSame(30.0, $after['cogs']['net']);
        $this->assertSame(90.0, $after['gross_profit'], '6 units still sold at a margin of 15');

        // Gross is untouched by the return — the pair is auditable only if the
        // original figure survives beside the credit.
        $this->assertSame($before['sales']['net'], $after['sales']['gross']);
        $this->assertSame($before['cogs']['net'], $after['cogs']['gross']);
    }

    #[Test]
    public function a_combo_sale_carries_the_cost_of_its_components(): void
    {
        /*
         * The asymmetry this guards is invisible on screen and easy to "tidy"
         * into a bug: revenue must EXCLUDE `children_type = 'combo'` lines,
         * because SellService prices them at zero and recalculateTotals()
         * ignores them — while cost must INCLUDE them, because for a combo it is
         * the child lines that hold the FIFO map. Filter both the same way and a
         * combo reports as pure margin at no cost at all.
         */
        $component = $this->createProduct(['name' => 'Combo component']);
        $componentVariation = $this->variationOf($component);

        $this->buy($componentVariation, 20, 6.00);

        $combo = $this->createProduct(['name' => 'Combo product', 'type' => 'combo']);
        $comboVariation = $this->variationOf($combo);
        $comboVariation->combo_variations = [
            ['variation_id' => $componentVariation->id, 'quantity' => 2],
        ];
        $comboVariation->save();

        // 3 combos → 6 components consumed at 6.00 = 36 cost, sold for 150.
        $this->sell($comboVariation, 3, 50.00);

        /*
         * This assertion found a real bug rather than confirming one. syncLines()
         * pushed only *parent* line ids into its kept-line list, then swept the
         * transaction for "lines not kept" and deleted them — which was exactly
         * the set of component lines consumeComboComponents() had just written.
         * Every combo sale therefore threw away its own components and released
         * the stock it had consumed, leaving the FIFO map empty and the combo
         * looking like pure margin. Fixed in SellService; guarded here.
         */
        $child = TransactionSellLine::where('children_type', 'combo')->first();
        $this->assertNotNull($child, 'The combo should have produced a child line.');
        $this->assertSame(6.0, (float) $child->quantity);
        $this->assertSame(0.0, (float) $child->unit_price_inc_tax, 'Child lines carry no money.');

        $pl = $this->reports->profitLoss($this->range);

        $this->assertSame(150.0, $pl['sales']['net'], 'The parent line is the revenue.');
        $this->assertSame(36.0, $pl['cogs']['net'], 'The child lines are the cost.');
        $this->assertSame(114.0, $pl['gross_profit']);
        $this->assertNotSame(150.0, $pl['gross_profit'], 'A costless combo is the bug.');
    }

    #[Test]
    public function expenses_fall_below_gross_profit_and_not_into_it(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->buy($variation, 10, 5.00);
        $this->sell($variation, 10, 20.00);
        $this->spend(60.00);

        $pl = $this->reports->profitLoss($this->range);

        $this->assertSame(150.0, $pl['gross_profit'], 'Untouched by expenses.');
        $this->assertSame(60.0, $pl['expenses']['net']);
        $this->assertSame(90.0, $pl['net_profit'], 'Gross profit less expenses.');
        $this->assertSame(75.0, $pl['margin'], '150 gross profit on 200 of net sales');
    }

    #[Test]
    public function a_period_with_no_trade_reports_zeroes_rather_than_dividing_by_zero(): void
    {
        // The normal state of a fresh install, and one of the first screens a
        // new user opens — `margin` would be a division by zero unguarded.
        $pl = $this->reports->profitLoss($this->range);

        $this->assertSame(0.0, $pl['sales']['net']);
        $this->assertSame(0.0, $pl['cogs']['net']);
        $this->assertSame(0.0, $pl['gross_profit']);
        $this->assertSame(0.0, $pl['margin']);
    }

    #[Test]
    public function figures_outside_the_period_are_not_counted(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->buy($variation, 10, 5.00);
        $sale = $this->sell($variation, 10, 20.00);

        // Backdate the sale out of the window. The purchase stays put, so a
        // range filter applied to only one side of the margin shows up here.
        $sale->transaction_date = now()->subMonths(2)->startOfMonth();
        $sale->save();

        $pl = $this->reports->profitLoss($this->range);

        $this->assertSame(0.0, $pl['sales']['net']);
        $this->assertSame(0.0, $pl['cogs']['net'], 'Cost follows the sale, not the purchase.');

        $lastQuarter = [
            'start' => now()->subMonths(3)->startOfMonth()->toDateString(),
            'end' => now()->endOfMonth()->toDateString(),
        ];

        $this->assertSame(200.0, $this->reports->profitLoss($lastQuarter)['sales']['net']);
    }

    #[Test]
    public function a_sale_on_the_closing_day_is_inside_the_period(): void
    {
        /*
         * `transaction_date` is a dateTime, so a whereBetween on two bare dates
         * silently drops everything after midnight on the closing day — a whole
         * day's trade missing from a month-end report, with nothing on screen to
         * say so. ReportService::scoped() appends 23:59:59 for exactly this.
         */
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->buy($variation, 5, 5.00);
        $sale = $this->sell($variation, 5, 20.00);

        $sale->transaction_date = now()->endOfMonth()->setTime(18, 30);
        $sale->save();

        $this->assertSame(100.0, $this->reports->profitLoss($this->range)['sales']['net']);
    }

    /* ================================================================
     | Tax
     ================================================================ */

    #[Test]
    public function tax_owed_is_output_tax_net_of_input_tax(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);
        $vat = $this->taxRate(15.0);

        $this->buy($variation, 10, 5.00, $vat->id);      // 50 net → 7.50 input
        $sale = $this->sell($variation, 10, 20.00, $vat->id); // 200 net → 30.00 output

        $summary = $this->reports->taxSummary($this->range);

        $this->assertSame(30.0, $summary['output']['net'], 'Charged on sales.');
        $this->assertSame(7.5, $summary['input']['net'], 'Paid on purchases.');
        $this->assertSame(22.5, $summary['payable'], 'Owed to the authority.');

        /*
         * A return has to carry its tax back. `addReturn()` records the value of
         * the goods but does not (yet) restate order tax on the credit note, so
         * the figure is set here directly — which is honest about what is under
         * test: taxSummary() reads the document-level `tax_amount`, and what is
         * being asserted is that it *nets* a credit note rather than adding it.
         */
        $this->sells->addReturn($sale, [[
            'sell_line_id' => $sale->sell_lines->first()->id,
            'quantity' => 5,
        ]], ['created_by' => $this->user->id]);

        Transaction::where('type', TransactionTypes::SELL_RETURN)
            ->update(['tax_amount' => 15.00]);

        $after = $this->reports->taxSummary($this->range);

        $this->assertSame(30.0, $after['output']['gross'], 'The invoice still charged 30.');
        $this->assertSame(15.0, $after['output']['returned']);
        $this->assertSame(15.0, $after['output']['net']);
        $this->assertSame(7.5, $after['payable'], '15 output less 7.50 input');
    }

    #[Test]
    public function more_input_tax_than_output_is_reclaimable_rather_than_owed(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);
        $vat = $this->taxRate(15.0);

        // A month of stocking up and little selling — a real and common shape,
        // and the one an unsigned "payable" would report backwards.
        $this->buy($variation, 100, 5.00, $vat->id);  // 500 net → 75.00 input
        $this->sell($variation, 2, 20.00, $vat->id);  //  40 net →  6.00 output

        $summary = $this->reports->taxSummary($this->range);

        $this->assertSame(-69.0, $summary['payable']);
        $this->assertLessThan(0, $summary['payable'], 'Negative means reclaimable.');
    }

    /* ================================================================
     | Purchase & sell, expenses, stock
     ================================================================ */

    #[Test]
    public function purchase_and_sell_compares_the_two_sides_without_calling_it_profit(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $this->buy($variation, 10, 5.00);
        $this->sell($variation, 4, 20.00);

        $totals = $this->reports->purchaseAndSell($this->range);

        $this->assertSame(50.0, $totals['purchase']['total']);
        $this->assertSame(80.0, $totals['sell']['total']);
        $this->assertSame(1, $totals['purchase']['count']);
        $this->assertSame(1, $totals['sell']['count']);

        // Deliberately NOT profit: it compares a period's buying against its
        // selling, and stock bought this month is usually sold next month. Six
        // units are still on the shelf here.
        $this->assertSame(30.0, $totals['difference']);
        $this->assertNotSame(
            $this->reports->profitLoss($this->range)['gross_profit'],
            $totals['difference'],
            'The two figures answer different questions and must not coincide.'
        );
    }

    #[Test]
    public function stock_on_hand_is_valued_at_cost_and_at_what_it_could_fetch(): void
    {
        $product = $this->createProduct();
        $variation = $this->variationOf($product);

        $variation->dpp_inc_tax = 5.00;
        $variation->sell_price_inc_tax = 20.00;
        $variation->save();

        $this->buy($variation, 10, 5.00);
        $this->sell($variation, 4, 20.00);

        $totals = $this->reports->stockTotals($this->reports->stockOnHand());

        $this->assertSame(6.0, $totals['quantity'], '10 bought, 4 sold.');
        $this->assertSame(30.0, $totals['value'], 'At cost.');
        $this->assertSame(120.0, $totals['potential'], 'At the selling price.');
        $this->assertSame(1, $totals['lines']);
    }

    #[Test]
    public function the_stock_totals_agree_with_the_rows_beneath_them(): void
    {
        /*
         * This is the regression test for the 500 that shipped with the first
         * tranche. stockTotals() aggregates a CLONE of the query the table
         * shows, which already carries a thirteen-column select list — so it
         * must REPLACE that list, not append to it. Written with selectRaw()
         * (which is addSelect() under another name) the statement selected
         * SUM(...) beside products.id with no GROUP BY, and MySQL 8 rejected it
         * outright under only_full_group_by: error 1140. Every open of
         * reports.stock was a 500.
         *
         * Asserting the totals equal the sum of the rows covers both halves at
         * once: the query must execute, and it must aggregate the same rows the
         * table lists.
         */
        $first = $this->createProduct(['name' => 'Stock A']);
        $second = $this->createProduct(['name' => 'Stock B']);

        foreach ([[$first, 5.0, 12.0, 4.0], [$second, 7.5, 19.0, 2.0]] as [$product, $cost, $price, $qty]) {
            $variation = $this->variationOf($product);
            $variation->dpp_inc_tax = $cost;
            $variation->sell_price_inc_tax = $price;
            $variation->save();

            $this->buy($variation, $qty, $cost);
        }

        $query = $this->reports->stockOnHand();

        $rows = $query->clone()->get();
        $totals = $this->reports->stockTotals($query);

        $this->assertCount(2, $rows);
        $this->assertSame($rows->count(), $totals['lines']);
        $this->assertSame(
            round((float) $rows->sum('stock_value'), 4),
            $totals['value'],
            'The headline figure must be the sum of the rows printed under it.'
        );
        $this->assertSame(
            round((float) $rows->sum('potential_value'), 4),
            $totals['potential']
        );

        // 4 x 5 + 2 x 7.5 = 35, and 4 x 12 + 2 x 19 = 86.
        $this->assertSame(35.0, $totals['value']);
        $this->assertSame(86.0, $totals['potential']);
    }

    #[Test]
    public function expenses_are_grouped_by_category_with_refunds_netted_off(): void
    {
        $this->spend(100.00);
        $this->spend(40.00);

        $summary = $this->reports->expenseValue($this->range);
        $this->assertSame(140.0, $summary['net']);

        $rows = $this->reports->expensesByCategory($this->range);
        $this->assertCount(2, $rows, 'One row per category.');
        $this->assertSame(100.0, (float) $rows->first()->spent, 'Ordered by size.');

        // A refund makes the category cost less, rather than appearing as
        // income of its own.
        $refunded = Transaction::where('type', TransactionTypes::EXPENSE)
            ->orderByDesc('final_total')->first();

        $refunded->replicate()->fill([
            'type' => TransactionTypes::EXPENSE_REFUND,
            'final_total' => 25.00,
        ])->save();

        $this->assertSame(115.0, $this->reports->expenseValue($this->range)['net']);
    }

    /* ================================================================
     | The screens and the exports
     ================================================================ */

    /**
     * A tenant whose owner is a real admin, which `createTenant()` does not
     * build — `permit()` short-circuits on `isAdmin()`, and that is a *role*.
     */
    private function admin(): \App\Models\User
    {
        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $currency = \App\Models\Currency::firstOrCreate(
            ['code' => 'EGP'],
            ['country' => 'Egypt', 'currency' => 'Egyptian Pound', 'symbol' => 'ج.م',
                'thousand_separator' => ',', 'decimal_separator' => '.']
        );

        ['owner' => $owner, 'business' => $business] = app(BusinessService::class)->register(
            ['name' => 'Reports Co.', 'currency_id' => $currency->id],
            ['first_name' => 'Admin', 'username' => 'reports_'.uniqid(),
                'password' => 'secret-pass', 'language' => 'ar']
        );

        \App\Support\Tenancy::bind($business->id);

        return $owner;
    }

    #[Test]
    public function every_export_streams_a_spreadsheet(): void
    {
        /*
         * `reports.export` is the one report route the render walk cannot cover:
         * its `{report}` is a slug, and resolveParameters() falls back to a
         * fixture *id* for any parameter name it does not know — which is why
         * that walk asked for `/reports/627/export` and got the whitelist's 404.
         * So the five slugs are exercised here instead, by name.
         */
        $this->actingAs($this->admin());

        foreach (array_keys(\App\Http\Controllers\ReportController::REPORTS) as $slug) {
            $response = $this->get(route('reports.export', $slug));

            $response->assertOk();
            $this->assertStringContainsString(
                'spreadsheetml',
                $response->headers->get('content-type') ?? '',
                "Export `{$slug}` did not stream a spreadsheet."
            );
            $this->assertStringContainsString(
                $slug,
                $response->headers->get('content-disposition') ?? '',
                "Export `{$slug}` was not named after its report."
            );
        }
    }

    #[Test]
    public function an_unknown_export_slug_is_a_404_and_not_a_guess(): void
    {
        $this->actingAs($this->admin());

        $this->get('/reports/627/export')->assertNotFound();
        $this->get('/reports/profit_loss/export')->assertNotFound();
        $this->get('/reports/../export')->assertNotFound();
    }

    #[Test]
    public function the_hub_shows_a_limited_user_only_what_they_may_open(): void
    {
        $owner = $this->admin();
        $business = $owner->business_id;

        $role = Role::findOrCreate('Stock only #'.uniqid(), 'web');
        $role->givePermissionTo(Permission::findOrCreate('stock_report.view', 'web'));

        $clerk = \App\Models\User::create([
            'user_type' => 'user', 'first_name' => 'Clerk',
            'username' => 'clerk_'.uniqid(), 'password' => 'secret-pass',
            'language' => 'ar', 'status' => 'active', 'business_id' => $business,
            // Without this CheckUserLogin bounces them to /home with a 302, and
            // the test would be measuring the login gate instead of the report
            // gates it is about.
            'allow_login' => 1,
        ]);
        $clerk->assignRole($role);

        $this->actingAs($clerk);

        // The one report they hold, and the hub listing exactly one tile.
        $this->get(route('reports.stock'))->assertOk();
        $this->get(route('reports.index'))
            ->assertOk()
            ->assertSee(route('reports.stock'))
            ->assertDontSee(route('reports.profitLoss'));

        // A short hub rather than a wall of 403s — but the gates are real.
        foreach (['purchaseSell', 'profitLoss', 'tax', 'expenses'] as $route) {
            $this->get(route('reports.'.$route))->assertForbidden();
        }

        // The report is visible; the download is a separate permission.
        $this->get(route('reports.export', 'stock'))->assertForbidden();
    }

    #[Test]
    public function every_report_renders_with_no_filters_at_all(): void
    {
        /*
         * The hard constraint of the whole tranche: a report may never require a
         * query string. dateRange()'s current-month default is what makes the
         * bare URL work, and the bare URL is what the render walk — and a user
         * clicking through from the hub — actually opens.
         */
        $this->actingAs($this->admin());

        foreach (\App\Http\Controllers\ReportController::REPORTS as $slug => $report) {
            $this->get(route($report['route']))
                ->assertOk()
                ->assertDontSee('lang_v1.', escape: false);
        }
    }
}
