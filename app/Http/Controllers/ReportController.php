<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\Brands;
use App\Models\BusinessLocation;
use App\Models\Category;
use App\Models\ExpenseCategory;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The reports.
 *
 * Thin by design: every figure comes from {@see ReportService}, and what is left
 * here is which permission guards a screen, which filters it offers, and which
 * view renders it. That split is what lets the arithmetic be tested without a
 * request, in {@see \Tests\Feature\ReportsTest}.
 *
 * `REPORTS` below is deliberately the only list. It drives the hub tiles, the
 * per-action permission, and the export whitelist at once, so a sixth report
 * cannot be added to the hub while quietly missing its gate — which is the shape
 * this bug would otherwise take.
 *
 * This tranche covers five reports. Seven more (contacts, register, trending
 * products, sales representative, stock details, customer group, user
 * performance) are deferred and inherit all of this; see NOTES §13. Indian GST
 * reports are excluded on purpose — locked decision #2, the market is Egypt.
 */
class ReportController extends Controller
{
    /**
     * Every report in this tranche, keyed by its URL slug.
     *
     * The slug is also the export parameter, which is why the keys are
     * URL-shaped rather than permission-shaped.
     *
     * @var array<string, array<string, string>>
     */
    public const REPORTS = [
        'purchase-sell' => [
            'permission' => 'purchase_n_sell_report.view',
            'route' => 'reports.purchaseSell',
            'label' => 'lang_v1.purchase_n_sell_report',
            'desc' => 'lang_v1.purchase_n_sell_report_desc',
            'icon' => 'transfer',
        ],
        'stock' => [
            'permission' => 'stock_report.view',
            'route' => 'reports.stock',
            'label' => 'lang_v1.stock_report',
            'desc' => 'lang_v1.stock_report_desc',
            'icon' => 'layers',
        ],
        'profit-loss' => [
            'permission' => 'profit_loss_report.view',
            'route' => 'reports.profitLoss',
            'label' => 'lang_v1.profit_loss_report',
            'desc' => 'lang_v1.profit_loss_report_desc',
            'icon' => 'chart',
        ],
        'tax' => [
            'permission' => 'tax_report.view',
            'route' => 'reports.tax',
            'label' => 'lang_v1.tax_report',
            'desc' => 'lang_v1.tax_report_desc',
            'icon' => 'percent',
        ],
        'expenses' => [
            'permission' => 'expense_report.view',
            'route' => 'reports.expenses',
            'label' => 'lang_v1.expense_report',
            'desc' => 'lang_v1.expense_report_desc',
            'icon' => 'receipt',
        ],
    ];

    public function __construct(private ReportService $reports) {}

    /* ================================================================
     | Hub
     ================================================================ */

    /**
     * The hub the sidebar's single Reports entry points at.
     *
     * Tiles are filtered by permission rather than shown-and-403'd, so a
     * warehouse clerk with only `stock_report.view` sees one tile instead of a
     * wall of doors that do not open. No `permit()` call guards the hub itself:
     * an empty hub with an honest empty state is a better answer than a 403 on
     * the only Reports link in the sidebar.
     */
    public function index()
    {
        return view('report.index', [
            'reports' => collect(static::REPORTS)
                ->filter(fn (array $report) => $this->allows($report['permission']))
                ->all(),
        ]);
    }

    /* ================================================================
     | Reports
     ================================================================ */

    public function purchaseSell(Request $request)
    {
        $this->permit('purchase_n_sell_report.view');

        $range = $this->reports->dateRange($request);
        $locationId = $this->locationId($request);

        return view('report.purchase-sell', [
            'range' => $range,
            'totals' => $this->reports->purchaseAndSell($range, $locationId),
            'locations' => BusinessLocation::forDropdown(true),
        ]);
    }

    public function stock(Request $request)
    {
        $this->permit('stock_report.view');

        $query = $this->stockQuery($request);

        return view('report.stock', [
            /* No date range is passed, and that is deliberate: stock is a
               position, not a period. The filter bar renders its date pair only
               when given a range, so this screen shows none rather than offering
               a control it would ignore. */
            'records' => $query->clone()->paginate(50)->withQueryString(),
            'totals' => $this->reports->stockTotals($query),
            'locations' => BusinessLocation::forDropdown(true),
            'categories' => ['' => __('lang_v1.all')] + Category::forDropdown('product', false),
            'brands' => ['' => __('lang_v1.all')] + Brands::forDropdown(),
        ]);
    }

    public function profitLoss(Request $request)
    {
        $this->permit('profit_loss_report.view');

        $range = $this->reports->dateRange($request);

        return view('report.profit-loss', [
            'range' => $range,
            'figures' => $this->reports->profitLoss($range, $this->locationId($request)),
            'locations' => BusinessLocation::forDropdown(true),
        ]);
    }

    public function tax(Request $request)
    {
        $this->permit('tax_report.view');

        $range = $this->reports->dateRange($request);
        $locationId = $this->locationId($request);

        return view('report.tax', [
            'range' => $range,
            'summary' => $this->reports->taxSummary($range, $locationId),
            'rates' => $this->reports->taxByRate($range, $locationId),
            'locations' => BusinessLocation::forDropdown(true),
        ]);
    }

    public function expenses(Request $request)
    {
        $this->permit('expense_report.view');

        $range = $this->reports->dateRange($request);
        $locationId = $this->locationId($request);
        $categoryId = $this->intOrNull($request, 'expense_category_id');

        return view('report.expenses', [
            'range' => $range,
            'summary' => $this->reports->expenseValue($range, $locationId, $categoryId),
            'rows' => $this->reports->expensesByCategory($range, $locationId, $categoryId),
            'locations' => BusinessLocation::forDropdown(true),
            'categories' => ['' => __('lang_v1.all')] + ExpenseCategory::forDropdownWithSubs(),
        ]);
    }

    /* ================================================================
     | Export
     ================================================================ */

    /**
     * One export action for all five reports.
     *
     * Parameterised rather than five near-identical methods, so the
     * `view_export_buttons` gate and the `Excel::download()` call each exist
     * once. `$report` comes off the URL, so it is checked against the whitelist
     * before anything else happens — an unknown slug is a 404, not a guess.
     *
     * The export re-runs the report's own service call with the same request, so
     * the file cannot drift from the screen it was downloaded from.
     *
     * Figures are written as raw numbers, not through `format_currency()`: a
     * formatted string carries a currency symbol and localised digits, which
     * arrives in a spreadsheet as text that will not sum. Formatting is a
     * property of the screen, not of the data.
     */
    public function export(Request $request, string $report)
    {
        abort_unless(array_key_exists($report, static::REPORTS), 404);

        $this->permit(static::REPORTS[$report]['permission']);
        $this->permit('view_export_buttons');

        $range = $this->reports->dateRange($request);

        $rows = match ($report) {
            'purchase-sell' => $this->purchaseSellRows($request, $range),
            'stock' => $this->stockRows($request),
            'profit-loss' => $this->profitLossRows($request, $range),
            'tax' => $this->taxRows($request, $range),
            'expenses' => $this->expenseRows($request, $range),
        };

        /* Stock is a position, so its file is stamped with the day it was taken
           rather than a range it never filtered by. */
        $stamp = $report === 'stock'
            ? now()->toDateString()
            : $range['start'].'-'.$range['end'];

        return Excel::download(new ArrayExport($rows), $report.'-'.$stamp.'.xlsx');
    }

    /**
     * @param  array{start: string, end: string}  $range
     * @return array<int, array<int, mixed>>
     */
    protected function purchaseSellRows(Request $request, array $range): array
    {
        $t = $this->reports->purchaseAndSell($range, $this->locationId($request));

        return [
            [__('lang_v1.description'), __('lang_v1.count'), __('lang_v1.total'), __('lang_v1.paid'), __('lang_v1.due')],
            [__('lang_v1.total_purchase'), $t['purchase']['count'], $t['purchase']['total'], $t['purchase']['paid'], $t['purchase']['due']],
            [__('lang_v1.purchase_return'), $t['purchase_return']['count'], $t['purchase_return']['total'], null, null],
            [__('lang_v1.total_sell'), $t['sell']['count'], $t['sell']['total'], $t['sell']['paid'], $t['sell']['due']],
            [__('lang_v1.sell_return'), $t['sell_return']['count'], $t['sell_return']['total'], null, null],
            [__('lang_v1.net_purchase'), null, $t['net_purchase'], null, null],
            [__('lang_v1.net_sell'), null, $t['net_sell'], null, null],
            [__('lang_v1.sell_minus_purchase'), null, $t['difference'], null, null],
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function stockRows(Request $request): array
    {
        $rows = [[
            __('lang_v1.product'), __('lang_v1.sku'), __('lang_v1.variation'),
            __('lang_v1.business_location'), __('lang_v1.category'), __('lang_v1.brand'),
            __('lang_v1.current_stock'), __('lang_v1.unit'),
            __('lang_v1.stock_value'), __('lang_v1.potential_sale_value'),
        ]];

        /* Chunked rather than ->get(): a full stock listing is the one export
           here whose size is unbounded by a date range. */
        $this->stockQuery($request)->chunk(500, function ($chunk) use (&$rows) {
            foreach ($chunk as $row) {
                $rows[] = [
                    $row->product_name,
                    $row->sub_sku ?: $row->sku,
                    $row->variation_name === 'DUMMY' ? __('lang_v1.default') : $row->variation_name,
                    $row->location_name,
                    $row->category_name,
                    $row->brand_name,
                    (float) $row->qty_available,
                    $row->unit_name,
                    (float) $row->stock_value,
                    (float) $row->potential_value,
                ];
            }
        });

        return $rows;
    }

    /**
     * @param  array{start: string, end: string}  $range
     * @return array<int, array<int, mixed>>
     */
    protected function profitLossRows(Request $request, array $range): array
    {
        $f = $this->reports->profitLoss($range, $this->locationId($request));

        return [
            [__('lang_v1.description'), __('lang_v1.amount')],
            [__('lang_v1.total_sell'), $f['sales']['gross']],
            [__('lang_v1.sell_return'), -$f['sales']['returned']],
            [__('lang_v1.net_sell'), $f['sales']['net']],
            [__('lang_v1.cost_of_goods_sold'), -$f['cogs']['net']],
            [__('lang_v1.gross_profit'), $f['gross_profit']],
            [__('lang_v1.shipping_charges'), $f['shipping']],
            [__('lang_v1.discount'), -$f['discount']],
            [__('lang_v1.net_expense'), -$f['expenses']['net']],
            [__('lang_v1.net_profit'), $f['net_profit']],
            [__('lang_v1.gross_profit_margin'), $f['margin']],
        ];
    }

    /**
     * @param  array{start: string, end: string}  $range
     * @return array<int, array<int, mixed>>
     */
    protected function taxRows(Request $request, array $range): array
    {
        $locationId = $this->locationId($request);
        $summary = $this->reports->taxSummary($range, $locationId);

        $rows = [
            [__('lang_v1.description'), __('lang_v1.amount')],
            [__('lang_v1.output_tax'), $summary['output']['gross']],
            [__('lang_v1.output_tax_on_returns'), -$summary['output']['returned']],
            [__('lang_v1.input_tax'), -$summary['input']['gross']],
            [__('lang_v1.input_tax_on_returns'), $summary['input']['returned']],
            [__('lang_v1.tax_payable'), $summary['payable']],
            [],
            [
                __('lang_v1.tax_rate'), __('lang_v1.rate'), __('lang_v1.type'),
                __('lang_v1.documents'), __('lang_v1.taxable_amount'), __('lang_v1.tax_amount'),
            ],
        ];

        foreach ($this->reports->taxByRate($range, $locationId) as $rate) {
            $rows[] = [
                $rate->rate_name,
                (float) $rate->rate,
                __('lang_v1.'.$rate->type),
                (int) $rate->documents,
                (float) $rate->taxable,
                (float) $rate->tax,
            ];
        }

        return $rows;
    }

    /**
     * @param  array{start: string, end: string}  $range
     * @return array<int, array<int, mixed>>
     */
    protected function expenseRows(Request $request, array $range): array
    {
        $rows = [[
            __('lang_v1.expense_category'), __('lang_v1.documents'),
            __('lang_v1.total_expense'), __('lang_v1.refunds'), __('lang_v1.net_expense'),
        ]];

        $categories = $this->reports->expensesByCategory(
            $range,
            $this->locationId($request),
            $this->intOrNull($request, 'expense_category_id')
        );

        foreach ($categories as $row) {
            $rows[] = [
                $row->category_name ?: __('lang_v1.uncategorised'),
                (int) $row->documents,
                (float) $row->spent,
                (float) $row->refunded,
                round((float) $row->spent - (float) $row->refunded, 4),
            ];
        }

        return $rows;
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * The stock query, built once so the screen and its export cannot diverge.
     */
    protected function stockQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return $this->reports->stockOnHand(
            $this->locationId($request),
            $this->intOrNull($request, 'category_id'),
            $this->intOrNull($request, 'brand_id'),
        );
    }

    protected function locationId(Request $request): ?int
    {
        return $this->intOrNull($request, 'location_id');
    }

    /**
     * A filter value that is present and non-empty, as an int.
     *
     * The dropdowns submit `''` for "all", which `(int)` would turn into 0 —
     * and `where('location_id', 0)` matches nothing rather than everything.
     */
    protected function intOrNull(Request $request, string $key): ?int
    {
        return $request->filled($key) ? (int) $request->input($key) : null;
    }
}
