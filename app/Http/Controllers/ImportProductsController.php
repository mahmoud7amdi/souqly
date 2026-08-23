<?php

namespace App\Http\Controllers;

use App\Models\Brands;
use App\Models\Category;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Services\FormattingService;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bulk product import from .xlsx / .csv.
 *
 * Validates every row first and imports nothing unless the whole file is
 * clean — a half-imported catalogue is worse than none.
 */
class ImportProductsController extends Controller
{
    /**
     * Expected column order.
     *
     * @var array<int, string>
     */
    private const COLUMNS = [
        'name', 'brand', 'unit', 'category', 'sub_category', 'sku',
        'barcode_type', 'alert_quantity', 'tax_percent', 'tax_type',
        'purchase_price', 'sell_price', 'enable_stock',
    ];

    public function __construct(
        private ProductService $products,
        private FormattingService $format,
    ) {}

    public function index()
    {
        $this->permit('product.create');

        return view('import.products', ['columns' => static::COLUMNS]);
    }

    /**
     * Downloadable template with the exact expected headings.
     */
    public function template()
    {
        $this->permit('product.create');

        return Excel::download(
            new \App\Exports\ArrayExport([static::COLUMNS]),
            'product-import-template.xlsx'
        );
    }

    public function store(Request $request)
    {
        $this->permit('product.create');

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
        ]);

        try {
            $rows = Excel::toArray(new \App\Imports\RawImport, $request->file('file'))[0] ?? [];
        } catch (\Throwable $e) {
            return back()->with('status', $this->failed($e, __('lang_v1.import_unreadable_file')));
        }

        // Drop the heading row and any trailing blanks.
        array_shift($rows);
        $rows = array_values(array_filter($rows, fn ($row) => ! empty(array_filter($row))));

        if (empty($rows)) {
            return back()->with('status', ['success' => 0, 'msg' => __('lang_v1.import_file_empty')]);
        }

        [$parsed, $errors] = $this->parse($rows);

        if (! empty($errors)) {
            return back()->with('status', [
                'success' => 0,
                'msg' => __('lang_v1.import_failed_row_errors', ['count' => count($errors)]),
            ])->with('import_errors', $errors);
        }

        try {
            $imported = DB::transaction(function () use ($parsed) {
                $count = 0;

                foreach ($parsed as $row) {
                    $product = Product::create($row['product']);
                    $this->products->createSingleVariation($product, $row['prices']);
                    $count++;
                }

                return $count;
            });
        } catch (\Throwable $e) {
            return back()->with('status', $this->failed($e));
        }

        return redirect()->route('products.index')->with('status', $this->ok(
            __('lang_v1.import_succeeded', ['count' => $imported])
        ));
    }

    /**
     * Validate and normalise every row, resolving names to ids.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    protected function parse(array $rows): array
    {
        $units = Unit::pluck('id', 'actual_name');
        $brands = Brands::pluck('id', 'name');
        $categories = Category::where('category_type', 'product')->pluck('id', 'name');
        $taxes = TaxRate::pluck('id', 'amount');

        $parsed = [];
        $errors = [];
        $seenSkus = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2; // +1 for the heading, +1 for 1-based rows
            $data = array_combine(
                static::COLUMNS,
                array_pad(array_slice($row, 0, count(static::COLUMNS)), count(static::COLUMNS), null)
            );

            if (empty($data['name'])) {
                $errors[] = __('lang_v1.import_row_missing', ['row' => $line, 'field' => __('lang_v1.name')]);

                continue;
            }

            $unitName = trim((string) $data['unit']);
            $unitId = $units[$unitName] ?? null;

            if (empty($unitId)) {
                $errors[] = __('lang_v1.import_row_unknown', [
                    'row' => $line, 'field' => __('lang_v1.unit'), 'value' => $unitName,
                ]);

                continue;
            }

            $sku = trim((string) $data['sku']) ?: $this->products->generateSku();

            if (in_array($sku, $seenSkus, true) || $this->products->skuExists($sku)) {
                $errors[] = __('lang_v1.import_row_duplicate_sku', ['row' => $line, 'value' => $sku]);

                continue;
            }

            $seenSkus[] = $sku;

            $taxPercent = $this->format->numUf($data['tax_percent'] ?? 0);

            $parsed[] = [
                'product' => [
                    'name' => trim((string) $data['name']),
                    'type' => 'single',
                    'unit_id' => $unitId,
                    'brand_id' => $brands[trim((string) $data['brand'])] ?? null,
                    'category_id' => $categories[trim((string) $data['category'])] ?? null,
                    'sub_category_id' => $categories[trim((string) $data['sub_category'])] ?? null,
                    'tax' => $taxPercent > 0 ? ($taxes[(string) $taxPercent] ?? null) : null,
                    'tax_type' => in_array($data['tax_type'], ['inclusive', 'exclusive'], true)
                        ? $data['tax_type'] : 'exclusive',
                    'sku' => $sku,
                    'barcode_type' => in_array($data['barcode_type'],
                        \App\Support\TransactionTypes::barcodeTypes(), true)
                        ? $data['barcode_type'] : 'C128',
                    'alert_quantity' => $this->format->numUf($data['alert_quantity'] ?? 0),
                    'enable_stock' => in_array(
                        strtolower(trim((string) $data['enable_stock'])),
                        ['1', 'yes', 'true', 'y'],
                        true
                    ) ? 1 : 0,
                    'created_by' => auth()->id(),
                ],
                'prices' => [
                    'default_purchase_price' => $this->format->numUf($data['purchase_price'] ?? 0),
                    'default_sell_price' => $this->format->numUf($data['sell_price'] ?? 0),
                ],
            ];
        }

        return [$parsed, $errors];
    }
}
