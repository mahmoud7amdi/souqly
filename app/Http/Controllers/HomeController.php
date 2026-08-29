<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\ReportService;
use App\Services\StockService;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard.
 */
class HomeController extends Controller
{
    public function __construct(private ReportService $reports, private StockService $stock) {}

    public function index(Request $request)
    {
        $range = $this->dateRange($request);

        return view('home.index', [
            'totals' => $this->totals($range),
            'stockAlerts' => $this->stockAlerts()->take(10),
            'salesDues' => $this->paymentDues(TransactionTypes::SELL)->take(10),
            'purchaseDues' => $this->paymentDues(TransactionTypes::PURCHASE)->take(10),
            'salesTrend' => $this->salesTrend(),
            'range' => $range,
        ]);
    }

    /**
     * Headline figures for the selected period.
     *
     * @param  array{start: string, end: string}  $range
     * @return array<string, float>
     */
    public function totals(array $range): array
    {
        $sell = Transaction::where('type', TransactionTypes::SELL)
            ->where('status', TransactionTypes::STATUS_FINAL)
            ->whereBetween('transaction_date', [$range['start'].' 00:00:00', $range['end'].' 23:59:59'])
            ->permittedLocations()
            ->selectRaw('COALESCE(SUM(final_total), 0) AS total, COUNT(*) AS count')
            ->first();

        $purchase = Transaction::where('type', TransactionTypes::PURCHASE)
            ->whereBetween('transaction_date', [$range['start'].' 00:00:00', $range['end'].' 23:59:59'])
            ->permittedLocations()
            ->selectRaw('COALESCE(SUM(final_total), 0) AS total, COUNT(*) AS count')
            ->first();

        $expense = (float) Transaction::where('type', TransactionTypes::EXPENSE)
            ->whereBetween('transaction_date', [$range['start'].' 00:00:00', $range['end'].' 23:59:59'])
            ->permittedLocations()
            ->sum('final_total');

        $sellReturn = (float) Transaction::where('type', TransactionTypes::SELL_RETURN)
            ->whereBetween('transaction_date', [$range['start'].' 00:00:00', $range['end'].' 23:59:59'])
            ->permittedLocations()
            ->sum('final_total');

        return [
            'sell_total' => (float) $sell->total,
            'sell_count' => (int) $sell->count,
            'purchase_total' => (float) $purchase->total,
            'purchase_count' => (int) $purchase->count,
            'expense_total' => $expense,
            'sell_return_total' => $sellReturn,
            'net_sales' => round((float) $sell->total - $sellReturn, 4),
        ];
    }

    /**
     * Products at or below their alert quantity.
     *
     * The query itself lives in {@see StockService::lowStock()}, shared with the
     * nightly alert command so the panel and the notification can never
     * disagree about what "low" means.
     */
    public function stockAlerts()
    {
        return $this->stock->lowStock();
    }

    /**
     * Outstanding receivables or payables.
     */
    public function paymentDues(string $type)
    {
        return Transaction::with('contact')
            ->where('type', $type)
            ->whereIn('payment_status', [TransactionTypes::DUE, TransactionTypes::PARTIAL])
            ->whereIn('status', [TransactionTypes::STATUS_FINAL, TransactionTypes::STATUS_RECEIVED])
            ->permittedLocations()
            ->orderBy('transaction_date')
            ->get()
            ->map(function ($transaction) {
                $transaction->due = $transaction->due_amount;

                return $transaction;
            })
            ->filter(fn ($t) => $t->due > 0.0001)
            ->values();
    }

    /**
     * Daily net sales for the last 30 days, for the dashboard chart.
     *
     * @return array<int, array{date: string, total: float}>
     */
    public function salesTrend(int $days = 30): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        $rows = Transaction::where('type', TransactionTypes::SELL)
            ->where('status', TransactionTypes::STATUS_FINAL)
            ->where('transaction_date', '>=', $start)
            ->permittedLocations()
            ->selectRaw('DATE(transaction_date) AS day, SUM(final_total) AS total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $trend = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();

            $trend[] = [
                'date' => $date,
                'total' => (float) ($rows[$date] ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * Period selected on the dashboard, defaulting to this month.
     *
     * Delegated to {@see ReportService::dateRange()} rather than duplicated. The
     * two were identical, and the failure mode of two copies is not a crash — it
     * is "this month" quietly meaning something different on the dashboard than
     * in the report the dashboard links to.
     *
     * @return array{start: string, end: string}
     */
    protected function dateRange(Request $request): array
    {
        return $this->reports->dateRange($request);
    }

    /**
     * Dismiss the flash banner (used by the AJAX screens).
     */
    public function clearStatusMessage(Request $request)
    {
        $request->session()->forget('status');

        return response()->json(['success' => 1]);
    }
}
