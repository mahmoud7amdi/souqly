<?php

namespace App\Http\Controllers;

use App\Models\Barcode;
use App\Models\Brands;
use App\Models\Category;
use App\Models\Variation;
use App\Services\FormattingService;
use Illuminate\Http\Request;
use Picqer\Barcode\Types\TypeCode128;
use Picqer\Barcode\Types\TypeCode39;
use Picqer\Barcode\Types\TypeEan13;
use Picqer\Barcode\Types\TypeEan8;
use Picqer\Barcode\Types\TypeUpcA;

/**
 * Barcode / price label sheets.
 */
class LabelsController extends Controller
{
    public function __construct(private FormattingService $format) {}

    public function show(Request $request)
    {
        $this->permit('product.view');

        return view('labels.show', [
            'sheets' => Barcode::forDropdown(),
            'categories' => ['' => __('lang_v1.all')] + Category::forDropdown(),
            'brands' => ['' => __('lang_v1.all')] + Brands::forDropdown(),
        ]);
    }

    /**
     * Variation lookup for the label builder.
     */
    public function getProductsByFilters(Request $request)
    {
        $this->permit('product.view');

        $variations = Variation::with('product')
            ->whereHas('product', function ($query) use ($request) {
                $query->where('is_inactive', 0)
                    ->when($request->filled('category_id'),
                        fn ($q) => $q->where('category_id', $request->integer('category_id')))
                    ->when($request->filled('brand_id'),
                        fn ($q) => $q->where('brand_id', $request->integer('brand_id')))
                    ->when($request->filled('term'), function ($q) use ($request) {
                        $term = '%'.$request->string('term').'%';
                        $q->where(fn ($sub) => $sub->where('name', 'like', $term)
                            ->orWhere('sku', 'like', $term));
                    });
            })
            ->limit(100)
            ->get();

        return response()->json($variations->map(fn ($variation) => [
            'variation_id' => $variation->id,
            'text' => $variation->full_name,
            'sku' => $variation->sub_sku,
            // Formatted here, not in the browser: the currency symbol, its side and
            // the decimal count are business settings the picker has no access to.
            'price' => $this->format->currencyF($variation->sell_price_inc_tax),
        ]));
    }

    /**
     * Render the printable sheet.
     */
    public function preview(Request $request)
    {
        $this->permit('product.view');

        $validated = $request->validate([
            'barcode_setting_id' => 'required|integer|exists:barcodes,id',
            'variations' => 'required|array|min:1',
            'variations.*.variation_id' => 'required|integer|exists:variations,id',
            'variations.*.quantity' => 'required|integer|min:1|max:500',
            'show_price' => 'nullable|boolean',
            'show_name' => 'nullable|boolean',
            'show_business_name' => 'nullable|boolean',
        ]);

        $sheet = Barcode::findOrFail($validated['barcode_setting_id']);

        $labels = [];

        foreach ($validated['variations'] as $row) {
            $variation = Variation::with('product')->find($row['variation_id']);

            if (empty($variation)) {
                continue;
            }

            $svg = $this->renderBarcode(
                (string) $variation->sub_sku,
                (string) $variation->product->barcode_type
            );

            // One entry per copy requested — the sheet template just flows them.
            for ($copy = 0; $copy < (int) $row['quantity']; $copy++) {
                $labels[] = [
                    'name' => $variation->full_name,
                    'sku' => $variation->sub_sku,
                    'price' => (float) $variation->sell_price_inc_tax,
                    'barcode_svg' => $svg,
                ];
            }
        }

        return view('labels.preview', [
            'labels' => $labels,
            'sheet' => $sheet,
            'showPrice' => $request->boolean('show_price', true),
            'showName' => $request->boolean('show_name', true),
            'showBusinessName' => $request->boolean('show_business_name'),
        ]);
    }

    /**
     * Barcode as inline SVG — scales cleanly at print DPI, unlike a PNG, and
     * needs no temp files.
     *
     * Falls back to Code 128 when the value doesn't satisfy the product's
     * declared symbology (e.g. an EAN-13 needs exactly 12–13 digits), so a
     * bad SKU produces a scannable label instead of an exception.
     */
    protected function renderBarcode(string $value, string $type): string
    {
        $renderer = new \Picqer\Barcode\Renderers\SvgRenderer;
        $renderer->setForegroundColor([0, 0, 0]);

        $encoders = [
            'C128' => TypeCode128::class,
            'C39' => TypeCode39::class,
            'EAN-13' => TypeEan13::class,
            'EAN-8' => TypeEan8::class,
            'UPC-A' => TypeUpcA::class,
        ];

        foreach ([$type, 'C128'] as $attempt) {
            $encoderClass = $encoders[$attempt] ?? null;

            if (empty($encoderClass)) {
                continue;
            }

            try {
                $barcode = (new $encoderClass)->getBarcode($value);

                return $renderer->render($barcode, 200, 50);
            } catch (\Throwable) {
                // Try the fallback symbology.
            }
        }

        return '';
    }
}
