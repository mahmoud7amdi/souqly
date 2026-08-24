<?php

namespace App\Services;

use App\Models\BusinessLocation;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Support\TransactionTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Every figure the reports show.
 *
 * The reports are the first screens that ask the same question of the ledger
 * from several angles at once, so the arithmetic lives here rather than in the
 * controller: a report is a query plus a layout, and only the layout belongs to
 * a controller. It also means the figures are testable without a request —
 * {@see \Tests\Feature\ReportsTest} asserts the arithmetic directly, which is
 * the part a rendering test cannot see.
 *
 * Two conventions run through the whole class and are worth stating once:
 *
 * 1. **Costs and prices are both tax-inclusive.** Cost of goods sold reads
 *    `purchase_lines.purchase_price_inc_tax`, because that is the figure
 *    {@see StockService::consume()} already records as the cost of a sale. Using
 *    the ex-tax column here would be more orthodox accounting but would make the
 *    same sale show two different profits depending on which screen you opened,
 *    and a ledger that disagrees with itself is worse than one that is
 *    consistently conservative. Revenue is therefore also read inclusive of tax,
 *    so both sides of the margin sit on the same basis.
 *
 * 2. **Gross and returned are reported separately, never pre-netted.** Each
 *    figure comes back as `gross`, `returned` and `net`, where `net` is the
 *    difference of the other two. A single pre-netted number cannot be audited,
 *    and it is the shape in which a double-subtracted return hides.
 */
class ReportService
{
    public function __construct(private FormattingService $format) {}

    /* ====================================================================
     | Shared query plumbing
     ==================================================================== */

    /**
     * The period a report covers.
     *
     * Defaults to the current month, and that default is load-bearing rather
     * than cosmetic: every report has to render with no query string at all
     * (the route walk in {@see \Tests\Feature\ScreensRenderTest} opens them
     * bare), so no report may require a date to be chosen first.
     *
     * @return array{start: string, end: string}
     */
    public function dateRange(Request $request): array
    {
        return [
            'start' => $this->format->ufDate($request->input('start_date'))
                ?? now()->startOfMonth()->toDateString(),
            'end' => $this->format->ufDate($request->input('end_date'))
                ?? now()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * Transactions of the given type(s), within the period, that this user may see.
     *
     * The whole point of this method is that it exists once. The
     * date-window-plus-permitted-locations chain was written out four times in
     * {@see \App\Http\Controllers\HomeController::totals()} alone, and the
     * failure mode of copies is not a crash — it is "this month" quietly meaning
     * something different on two screens that are supposed to agree.
     *
     * The ' 00:00:00' / ' 23:59:59' suffixes matter: `transaction_date` is a
     * dateTime, so a plain `whereBetween` on two bare dates silently excludes
     * everything that happened after midnight on the closing day.
     *
     * @param  string|array<int, string>  $types
     * @param  array{start: string, end: string}  $range
     */
    public function scoped(string|array $types, array $range, ?int $locationId = null): Builder
    {
        return Transaction::ofType($types)
            ->permittedLocations()
            ->forLocation($locationId)
            ->whereBetween('transactions.transaction_date', [
                $range['start'].' 00:00:00',
                $range['end'].' 23:59:59',
            ]);
    }

    /**
     * Document count, value, amount paid and amount still owed for one type.
     *
     * `is_return` payments are excluded because they are money handed back
     * (change at the till, an over-refund), not money received.
     *
     * @param  string|array<int, string>  $types
     * @param  array{start: string, end: string}  $range
     * @param  string|null  $status  restrict to one document status
     * @return array{count: int, total: float, paid: float, due: float}
     */
    public function documentTotals(
        string|array $types,
        array $range,
        ?int $locationId = null,
        ?string $status = null
    ): array {
        $query = $this->scoped($types, $range, $locationId)
            ->when($status, fn ($q) => $q->where('transactions.status', $status));

        $totals = $query->clone()
            ->selectRaw('COALESCE(SUM(final_total), 0) AS total, COUNT(*) AS count')
            ->first();

        $paid = (float) TransactionPayment::whereIn(
            'transaction_id', $query->clone()->select('transactions.id')
        )->where('is_return', 0)->sum('amount');

        $total = (float) $totals->total;

        return [
            'count' => (int) $totals->count,
            'total' => round($total, 4),
            'paid' => round($paid, 4),
            'due' => round($total - $paid, 4),
        ];
    }

    /* ====================================================================
     | Purchase & sell
     ==================================================================== */

    /**
     * Both sides of the trade for the period, and the gap between them.
     *
     * @param  array{start: string, end: string}  $range
     * @return array<string, mixed>
     */
    public function purchaseAndSell(array $range, ?int $locationId = null): array
    {
        $sell = $this->documentTotals(
            TransactionTypes::SELL, $range, $locationId, TransactionTypes::STATUS_FINAL
        );
        $purchase = $this->documentTotals(TransactionTypes::PURCHASE, $range, $locationId);
        $sellReturn = $this->documentTotals(TransactionTypes::SELL_RETURN, $range, $locationId);
        $purchaseReturn = $this->documentTotals(TransactionTypes::PURCHASE_RETURN, $range, $locationId);

        $netSell = round($sell['total'] - $sellReturn['total'], 4);
        $netPurchase = round($purchase['total'] - $purchaseReturn['total'], 4);

        return [
            'sell' => $sell,
            'purchase' => $purchase,
            'sell_return' => $sellReturn,
            'purchase_return' => $purchaseReturn,
            'net_sell' => $netSell,
            'net_purchase' => $netPurchase,
            /* Not called "profit": it compares what was sold against what was
               bought in the same window, and stock bought this month is usually
               sold next month. Profit is {@see self::profitLoss()}, which costs
               each sale from the lot it actually came out of. */
            'difference' => round($netSell - $netPurchase, 4),
        ];
    }

    /* ====================================================================
     | Profit & loss
     ==================================================================== */

    /**
     * Line-level sales value, inclusive of tax.
     *
     * Combo child lines are excluded, matching
     * {@see SellService::recalculateTotals()}. Today they are always priced at
     * zero so including them would change nothing — but they exist to consume
     * stock, not to carry money, and a filter that says so is what keeps a later
     * change to combo pricing from silently double-counting revenue.
     *
     * @param  array{start: string, end: string}  $range
     * @return array{gross: float, returned: float, net: float}
     */
    public function salesValue(array $range, ?int $locationId = null): array
    {
        $row = $this->scoped(TransactionTypes::SELL, $range, $locationId)
            ->where('transactions.status', TransactionTypes::STATUS_FINAL)
            ->join('transaction_sell_lines as sl', 'sl.transaction_id', '=', 'transactions.id')
            ->where('sl.children_type', '!=', 'combo')
            ->selectRaw(
                'COALESCE(SUM(sl.quantity * sl.unit_price_inc_tax), 0) AS gross,
                 COALESCE(SUM(sl.quantity_returned * sl.unit_price_inc_tax), 0) AS returned'
            )
            ->first();

        return $this->grossReturnedNet((float) $row->gross, (float) $row->returned);
    }

    /**
     * Cost of goods sold, taken from the FIFO map.
     *
     * Deliberately *not* filtered on `children_type`: for a combo it is the
     * child lines that hold the map rows, because the components are the
     * physical goods that left the shelf. Excluding them here — the mirror of
     * what {@see self::salesValue()} must do — would report a combo sale as pure
     * margin with no cost at all.
     *
     * The join to the map is itself the filter that matters: only lines that
     * actually consumed a lot have rows, so nothing else needs excluding.
     *
     * @param  array{start: string, end: string}  $range
     * @return array{gross: float, returned: float, net: float}
     */
    public function costOfGoodsSold(array $range, ?int $locationId = null): array
    {
        $row = $this->scoped(TransactionTypes::SELL, $range, $locationId)
            ->where('transactions.status', TransactionTypes::STATUS_FINAL)
            ->join('transaction_sell_lines as sl', 'sl.transaction_id', '=', 'transactions.id')
            ->join('transaction_sell_lines_purchase_lines as map', 'map.sell_line_id', '=', 'sl.id')
            ->join('purchase_lines as pl', 'pl.id', '=', 'map.purchase_line_id')
            ->selectRaw(
                'COALESCE(SUM(map.quantity * pl.purchase_price_inc_tax), 0) AS gross,
                 COALESCE(SUM(map.qty_returned * pl.purchase_price_inc_tax), 0) AS returned'
            )
            ->first();

        return $this->grossReturnedNet((float) $row->gross, (float) $row->returned);
    }

    /**
     * Expenses for the period, with refunds netted off.
     *
     * `$categoryId` exists so the headline figures on the expense report can
     * honour the same category filter as the table underneath them. Without it
     * the tiles would keep showing the unfiltered total while the rows narrowed,
     * which reads as an arithmetic error rather than as a filter. The profit and
     * loss statement calls this with no category, which is what it wants.
     *
     * @param  array{start: string, end: string}  $range
     * @return array{gross: float, returned: float, net: float}
     */
    public function expenseValue(
        array $range,
        ?int $locationId = null,
        ?int $categoryId = null
    ): array {
        $rows = $this->scoped(
            [TransactionTypes::EXPENSE, TransactionTypes::EXPENSE_REFUND], $range, $locationId
        )
            ->when($categoryId, fn ($q) => $q->where(
                fn ($inner) => $inner->where('transactions.expense_category_id', $categoryId)
                    ->orWhere('transactions.expense_sub_category_id', $categoryId)
            ))
            ->selectRaw('transactions.type, COALESCE(SUM(final_total), 0) AS total')
            ->groupBy('transactions.type')
            ->pluck('total', 'type');

        return $this->grossReturnedNet(
            (float) ($rows[TransactionTypes::EXPENSE] ?? 0),
            (float) ($rows[TransactionTypes::EXPENSE_REFUND] ?? 0)
        );
    }

    /**
     * The full profit and loss statement.
     *
     * Reads as a ledger, top to bottom, so every figure can be checked against
     * the ones above it:
     *
     *     net sales     −  cost of goods sold           =  gross profit
     *     gross profit  +  shipping  −  discounts  −  expenses  =  net profit
     *
     * Document-level discounts and shipping are separate lines rather than being
     * folded into sales, because {@see self::salesValue()} is line-level while an
     * invoice discount is applied to the document. Folding them in would make the
     * sales figure impossible to reconcile against the invoices themselves.
     *
     * @param  array{start: string, end: string}  $range
     * @return array<string, mixed>
     */
    public function profitLoss(array $range, ?int $locationId = null): array
    {
        $sales = $this->salesValue($range, $locationId);
        $cogs = $this->costOfGoodsSold($range, $locationId);
        $expenses = $this->expenseValue($range, $locationId);

        $sells = fn () => $this->scoped(TransactionTypes::SELL, $range, $locationId)
            ->where('transactions.status', TransactionTypes::STATUS_FINAL);

        $discount = (float) $sells()->sum('discount_amount');
        $shipping = (float) $sells()->sum('shipping_charges');

        $grossProfit = round($sales['net'] - $cogs['net'], 4);
        $netProfit = round($grossProfit + $shipping - $discount - $expenses['net'], 4);

        return [
            'sales' => $sales,
            'cogs' => $cogs,
            'expenses' => $expenses,
            'discount' => round($discount, 4),
            'shipping' => round($shipping, 4),
            'gross_profit' => $grossProfit,
            'net_profit' => $netProfit,
            /* Margin on net sales, not on gross: a shop with heavy returns is not
               as profitable as its invoices suggest. Guarded because a period
               with no sales at all is the normal state of a fresh install, and a
               division by zero there is a 500 on one of the first screens a new
               user opens. */
            'margin' => $sales['net'] > 0
                ? round($grossProfit / $sales['net'] * 100, 2)
                : 0.0,
        ];
    }

    /* ====================================================================
     | Tax
     ==================================================================== */

    /**
     * Output tax against input tax, and what that leaves owing.
     *
     * Reads the document-level `tax_amount`, which is what an invoice actually
     * charged, rather than re-deriving it from the lines: the figure a tax
     * authority asks about is the one printed on the document.
     *
     * @param  array{start: string, end: string}  $range
     * @return array<string, mixed>
     */
    public function taxSummary(array $range, ?int $locationId = null): array
    {
        $output = $this->grossReturnedNet(
            (float) $this->scoped(TransactionTypes::SELL, $range, $locationId)
                ->where('transactions.status', TransactionTypes::STATUS_FINAL)
                ->sum('tax_amount'),
            (float) $this->scoped(TransactionTypes::SELL_RETURN, $range, $locationId)
                ->sum('tax_amount')
        );

        $input = $this->grossReturnedNet(
            (float) $this->scoped(TransactionTypes::PURCHASE, $range, $locationId)
                ->sum('tax_amount'),
            (float) $this->scoped(TransactionTypes::PURCHASE_RETURN, $range, $locationId)
                ->sum('tax_amount')
        );

        return [
            'output' => $output,
            'input' => $input,
            // Positive means tax is owed to the authority; negative is reclaimable.
            'payable' => round($output['net'] - $input['net'], 4),
        ];
    }

    /**
     * Tax broken down by the rate that was applied.
     *
     * Grouped on the document's `tax_id`, so a rate that was never used does not
     * appear — an empty row for every rate on file reads as a fault.
     *
     * @param  array{start: string, end: string}  $range
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function taxByRate(array $range, ?int $locationId = null): \Illuminate\Support\Collection
    {
        return $this->scoped(
            [
                TransactionTypes::SELL, TransactionTypes::SELL_RETURN,
                TransactionTypes::PURCHASE, TransactionTypes::PURCHASE_RETURN,
            ],
            $range,
            $locationId
        )
            ->join('tax_rates as tr', 'tr.id', '=', 'transactions.tax_id')
            ->selectRaw(
                'tr.name AS rate_name, tr.amount AS rate, transactions.type AS type,
                 COALESCE(SUM(transactions.tax_amount), 0) AS tax,
                 COALESCE(SUM(transactions.total_before_tax), 0) AS taxable,
                 COUNT(*) AS documents'
            )
            ->groupBy('tr.id', 'tr.name', 'tr.amount', 'transactions.type')
            ->orderBy('tr.name')
            ->get();
    }

    /* ====================================================================
     | Expenses
     ==================================================================== */

    /**
     * Expense totals per category, refunds netted off within each category.
     *
     * A refund is subtracted rather than listed separately because the question
     * this report answers is "what did this category cost us", and money coming
     * back makes that number smaller — the same reasoning as
     * {@see \App\Http\Controllers\ExpenseController::listTotals()}.
     *
     * @param  array{start: string, end: string}  $range
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function expensesByCategory(
        array $range,
        ?int $locationId = null,
        ?int $categoryId = null
    ): \Illuminate\Support\Collection {
        return $this->scoped(
            [TransactionTypes::EXPENSE, TransactionTypes::EXPENSE_REFUND], $range, $locationId
        )
            ->when($categoryId, fn ($q) => $q->where(
                fn ($inner) => $inner->where('transactions.expense_category_id', $categoryId)
                    ->orWhere('transactions.expense_sub_category_id', $categoryId)
            ))
            ->leftJoin('expense_categories as ec', 'ec.id', '=', 'transactions.expense_category_id')
            ->selectRaw(
                'ec.id AS category_id, ec.name AS category_name,
                 COALESCE(SUM(CASE WHEN transactions.type = ? THEN final_total ELSE 0 END), 0) AS spent,
                 COALESCE(SUM(CASE WHEN transactions.type = ? THEN final_total ELSE 0 END), 0) AS refunded,
                 COUNT(*) AS documents',
                [TransactionTypes::EXPENSE, TransactionTypes::EXPENSE_REFUND]
            )
            ->groupBy('ec.id', 'ec.name')
            ->orderByDesc('spent')
            ->get();
    }

    /* ====================================================================
     | Stock
     ==================================================================== */

    /**
     * Stock on hand, one row per variation per location.
     *
     * Starts from `products` rather than from `variation_location_details`
     * because only `products` carries `business_id` — the tenant filter is a
     * global scope on the model, so a query rooted in the detail table would
     * have no tenant filter at all.
     *
     * Returns a query rather than results, so the screen can paginate it and the
     * export can walk it without a second definition of the same joins.
     */
    public function stockOnHand(
        ?int $locationId = null,
        ?int $categoryId = null,
        ?int $brandId = null
    ): Builder {
        $permitted = BusinessLocation::permittedLocations();

        return Product::query()
            ->join('variations as v', 'v.product_id', '=', 'products.id')
            ->join('variation_location_details as vld', 'vld.variation_id', '=', 'v.id')
            ->join('business_locations as bl', 'bl.id', '=', 'vld.location_id')
            ->leftJoin('units as u', 'u.id', '=', 'products.unit_id')
            ->leftJoin('categories as c', 'c.id', '=', 'products.category_id')
            ->leftJoin('brands as b', 'b.id', '=', 'products.brand_id')
            ->where('products.enable_stock', 1)
            ->when($permitted !== 'all', fn ($q) => $q->whereIn('vld.location_id', (array) $permitted))
            ->when($locationId, fn ($q) => $q->where('vld.location_id', $locationId))
            ->when($categoryId, fn ($q) => $q->where(
                fn ($inner) => $inner->where('products.category_id', $categoryId)
                    ->orWhere('products.sub_category_id', $categoryId)
            ))
            ->when($brandId, fn ($q) => $q->where('products.brand_id', $brandId))
            ->select([
                'products.id as product_id',
                'products.name as product_name',
                'products.sku as sku',
                'v.id as variation_id',
                'v.name as variation_name',
                'v.sub_sku as sub_sku',
                'v.dpp_inc_tax as unit_cost',
                'v.sell_price_inc_tax as unit_price',
                'vld.qty_available as qty_available',
                'bl.name as location_name',
                'u.short_name as unit_name',
                'c.name as category_name',
                'b.name as brand_name',
            ])
            ->selectRaw('(vld.qty_available * v.dpp_inc_tax) AS stock_value')
            ->selectRaw('(vld.qty_available * v.sell_price_inc_tax) AS potential_value')
            ->orderBy('products.name')
            ->orderBy('v.id')
            /* `vld.id` is the tiebreaker, and it is not cosmetic: one variation
               stocked in two locations produces two rows with the same
               `products.name` and the same `v.id`, and `chunk()` pages by offset.
               Without a unique final sort the export silently skips and repeats
               rows at every chunk boundary. */
            ->orderBy('vld.id');
    }

    /**
     * Headline figures for the stock report.
     *
     * Aggregated from a clone of the very query the table shows, so the totals
     * cannot drift from the rows underneath them.
     *
     * Two things here are load-bearing, and getting either wrong is a hard SQL
     * error rather than a wrong number:
     *
     * 1. **`select()`, not `selectRaw()`.** `selectRaw()` *appends* — it is
     *    `addSelect()` under a different name. Clone a query that already
     *    carries the thirteen columns {@see stockOnHand()} selects, append four
     *    aggregates, and you get `SUM(...)` sitting beside `products.id` with no
     *    `GROUP BY`, which MySQL 8 refuses outright under its default
     *    `only_full_group_by`: *"1140 In aggregated query without GROUP BY,
     *    expression #1 of SELECT list contains nonaggregated column
     *    products.id"*. `select()` replaces the list, so nothing but the
     *    aggregates travels. This shipped the wrong way round and took
     *    `reports.stock` down with a 500 on every open.
     * 2. **`reorder()`.** The inherited `ORDER BY products.name` is the same
     *    violation one clause further along, because those columns are no longer
     *    selected. Ordering a single-row aggregate is meaningless anyway.
     *
     * Sibling aggregates in this class ({@see taxByRate()},
     * {@see expensesByCategory()}) build on {@see scoped()}, which sets no
     * columns at all, so appending is safe there. It is only unsafe on a query
     * that already has a select list of its own — which, today, is this one.
     *
     * @return array{value: float, potential: float, quantity: float, lines: int}
     */
    public function stockTotals(Builder $query): array
    {
        $row = $query->clone()
            ->reorder()
            ->select(DB::raw(
                'COALESCE(SUM(vld.qty_available * v.dpp_inc_tax), 0) AS total_value,
                 COALESCE(SUM(vld.qty_available * v.sell_price_inc_tax), 0) AS total_potential,
                 COALESCE(SUM(vld.qty_available), 0) AS total_quantity,
                 COUNT(*) AS total_lines'
            ))
            ->first();

        return [
            'value' => round((float) $row->total_value, 4),
            'potential' => round((float) $row->total_potential, 4),
            'quantity' => round((float) $row->total_quantity, 4),
            'lines' => (int) $row->total_lines,
        ];
    }

    /* ====================================================================
     | Internals
     ==================================================================== */

    /**
     * @return array{gross: float, returned: float, net: float}
     */
    protected function grossReturnedNet(float $gross, float $returned): array
    {
        return [
            'gross' => round($gross, 4),
            'returned' => round($returned, 4),
            'net' => round($gross - $returned, 4),
        ];
    }
}
