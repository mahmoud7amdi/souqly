<?php

namespace App\Http\Controllers;

use App\Events\ProductsCreatedOrModified;
use App\Models\Brands;
use App\Models\BusinessLocation;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellingPriceGroup;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Variation;
use App\Models\VariationGroupPrice;
use App\Models\VariationTemplate;
use App\Models\Warranty;
use App\Services\FormattingService;
use App\Services\ProductService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Products: single, variable and combo, with their variations, price groups
 * and location restrictions.
 */
class ProductController extends Controller
{
    public function __construct(
        private ProductService $products,
        private StockService $stock,
        private FormattingService $format,
    ) {}

    public function index(Request $request)
    {
        $this->permit('product.view');

        // `variations` is not decoration: the row shows the default sell price,
        // which lives on the variation, so every row touches it. Left lazy it is
        // 25 extra queries per page — and a LazyLoadingViolationException locally.
        $products = Product::with(['brand', 'unit', 'category', 'product_tax', 'variations'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term));
            })
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('unit_id'), fn ($q) => $q->where('unit_id', $request->integer('unit_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->input('status') === 'inactive', fn ($q) => $q->where('is_inactive', 1))
            ->when($request->input('status') !== 'inactive', fn ($q) => $q->where('is_inactive', 0))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Total stock per product across the permitted locations, in one query.
        $stockByProduct = $this->stockTotals($products->pluck('id')->all());

        return view('product.index', [
            'products' => $products,
            'stockByProduct' => $stockByProduct,
            'categories' => Category::forDropdown(),
            'brands' => Brands::forDropdown(true),
            'units' => Unit::forDropdown(),
            'types' => Product::types(),
            'showPurchasePrice' => $this->allows('view_purchase_price'),
        ]);
    }

    public function create()
    {
        $this->permit('product.create');

        return view('product.create', $this->formData() + [
            'suggestedSku' => $this->products->generateSku(),
        ]);
    }

    public function store(Request $request)
    {
        $this->permit('product.create');

        $validated = $this->validateProduct($request);

        try {
            $product = DB::transaction(function () use ($validated, $request) {
                $product = Product::create($this->productAttributes($validated, $request));

                $this->buildVariations($product, $request);
                $this->syncLocations($product, $request);

                event(new ProductsCreatedOrModified($product));

                return $product;
            });

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        // "Save & add opening stock" jumps straight to the stock screen.
        if ($request->input('submit_type') === 'submit_n_add_opening_stock') {
            return redirect()->route('opening-stock.add', $product->id)->with('status', $output);
        }

        return $this->backToIndex('products.index', $output);
    }

    public function show(int $id)
    {
        $this->permit('product.view');

        $product = Product::with([
            'brand', 'unit', 'second_unit', 'category', 'sub_category',
            'product_tax', 'warranty', 'variations.group_prices.price_group',
            'variations.variation_location_details.location', 'product_locations',
        ])->findOrFail($id);

        return view('product.show', [
            'product' => $product,
            'showPurchasePrice' => $this->allows('view_purchase_price'),
        ]);
    }

    public function edit(int $id)
    {
        $this->permit('product.update');

        $product = Product::with(['variations.product_variation', 'product_locations'])
            ->findOrFail($id);

        return view('product.edit', $this->formData() + ['product' => $product]);
    }

    public function update(Request $request, int $id)
    {
        $this->permit('product.update');

        $product = Product::findOrFail($id);
        $validated = $this->validateProduct($request, $product);

        try {
            DB::transaction(function () use ($product, $validated, $request) {
                $product->update($this->productAttributes($validated, $request, $product));

                // The type is immutable once variations carry stock — changing
                // single↔variable would orphan the FIFO lots.
                $this->updateVariationPrices($product, $request);
                $this->syncLocations($product, $request);

                event(new ProductsCreatedOrModified($product));
            });

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return $this->backToIndex('products.index', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->permit('product.delete');

        try {
            $product = Product::findOrFail($id);

            // Refuse while any lot of any variation has movement recorded.
            $hasMovement = $product->variations()
                ->whereHas('purchase_lines')
                ->orWhereHas('sell_lines')
                ->exists();

            if ($hasMovement) {
                $output = ['success' => 0, 'msg' => __('lang_v1.cannot_delete_product_has_transactions')];
            } else {
                DB::transaction(function () use ($product) {
                    $product->variations()->forceDelete();
                    $product->product_variations()->delete();
                    $product->product_locations()->detach();
                    $product->delete();
                });

                $output = $this->ok(__('lang_v1.deleted_successfully'));
            }
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('products.index', $output);
    }

    /* ================================================================
     | Bulk actions
     ================================================================ */

    public function massDeactivate(Request $request)
    {
        $this->permit('product.update');

        $request->validate(['product_ids' => 'required|array']);

        Product::whereIn('id', $request->array('product_ids'))->update(['is_inactive' => 1]);

        return $this->backToIndex('products.index', $this->ok(__('lang_v1.updated_successfully')));
    }

    public function activate(int $id)
    {
        $this->permit('product.update');

        Product::findOrFail($id)->update(['is_inactive' => 0]);

        return $this->backToIndex('products.index', $this->ok(__('lang_v1.updated_successfully')));
    }

    /* ================================================================
     | Selling price groups
     ================================================================ */

    public function addSellingPrices(int $id)
    {
        $this->permit('product.update');

        $product = Product::with('variations.group_prices')->findOrFail($id);

        return view('product.selling-prices', [
            'product' => $product,
            'priceGroups' => SellingPriceGroup::active()->get(),
        ]);
    }

    public function saveSellingPrices(Request $request, int $id)
    {
        $this->permit('product.update');

        $product = Product::with('variations')->findOrFail($id);

        try {
            DB::transaction(function () use ($product, $request) {
                foreach ($request->input('group_prices', []) as $variationId => $groups) {
                    // Only touch variations that actually belong to this product.
                    if (! $product->variations->contains('id', $variationId)) {
                        continue;
                    }

                    foreach ($groups as $groupId => $row) {
                        VariationGroupPrice::updateOrCreate(
                            ['variation_id' => $variationId, 'price_group_id' => $groupId],
                            [
                                'price_inc_tax' => $this->format->numUf($row['price'] ?? 0),
                                'price_type' => ($row['type'] ?? 'fixed') === 'percentage'
                                    ? 'percentage' : 'fixed',
                            ]
                        );
                    }
                }
            });

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return redirect()->route('products.show', $product->id)->with('status', $output);
    }

    /* ================================================================
     | AJAX helpers (POS, purchase and product forms)
     ================================================================ */

    /**
     * Product search for the POS and purchase screens.
     */
    public function getProducts(Request $request)
    {
        $term = (string) $request->input('term', '');
        $locationId = $request->integer('location_id') ?: null;
        $priceGroupId = $request->integer('price_group_id') ?: null;

        $variations = Variation::query()
            ->with(['product.unit'])
            ->whereHas('product', function ($query) use ($locationId) {
                $query->where('is_inactive', 0)
                    ->where('not_for_selling', 0)
                    ->forLocation($locationId);
            })
            ->where(function ($query) use ($term) {
                $query->where('sub_sku', 'like', $term.'%')
                    ->orWhere('name', 'like', '%'.$term.'%')
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$term.'%')
                        ->orWhere('sku', 'like', $term.'%'));
            })
            ->limit(25)
            ->get();

        return response()->json($variations->map(fn ($variation) => [
            'variation_id' => $variation->id,
            'product_id' => $variation->product_id,
            'text' => $variation->full_name,
            'sku' => $variation->sub_sku,
            'unit' => $variation->product->unit->short_name ?? '',
            'enable_stock' => (bool) $variation->product->enable_stock,
            'qty_available' => $locationId
                ? $this->stock->currentStock($variation->id, $locationId)
                : null,
            'selling_price' => $this->products->sellPriceFor($variation, $priceGroupId),
            'purchase_price' => $this->allows('view_purchase_price')
                ? (float) $variation->dpp_inc_tax
                : null,
            'tax_id' => $variation->product->tax,
            'tax_type' => $variation->product->tax_type,

            // null rather than the placeholder URL when there is no picture, so
            // the POS grid can draw a muted icon instead of 25 identical grey
            // rectangles. See Product::hasImage().
            'image_url' => $variation->product->hasImage()
                ? $variation->product->image_url
                : null,
        ]));
    }

    /**
     * Sub categories of a category, for the cascading select.
     */
    public function getSubCategories(Request $request)
    {
        $request->validate(['category_id' => 'required|integer']);

        return response()->json(
            Category::subCategoriesForDropdown($request->integer('category_id'))
        );
    }

    /**
     * Sub units of a unit, with their multipliers.
     */
    public function getSubUnits(Request $request)
    {
        $request->validate(['unit_id' => 'required|integer']);

        return response()->json(Unit::subUnitsWithMultiplier($request->integer('unit_id')));
    }

    /**
     * Values of a variation template, for the variable-product form.
     */
    public function getVariationTemplate(Request $request)
    {
        $request->validate(['template_id' => 'required|integer']);

        $template = VariationTemplate::with('values')->find($request->integer('template_id'));

        return response()->json([
            'name' => $template->name ?? '',
            'values' => $template?->values->pluck('name') ?? [],
        ]);
    }

    /**
     * Live SKU uniqueness check for the product form.
     */
    public function checkProductSku(Request $request)
    {
        $exists = $this->products->skuExists(
            (string) $request->input('sku'),
            $request->integer('product_id') ?: null
        );

        return response()->json(['valid' => ! $exists]);
    }

    /**
     * Price-change history for a variation.
     */
    public function priceHistory(int $variationId)
    {
        $this->permit('product.view');

        $variation = Variation::with(['product', 'price_history.createdBy'])
            ->findOrFail($variationId);

        return view('product.price-history', ['variation' => $variation]);
    }

    /**
     * Stock across locations for one product.
     */
    public function productStockHistory(int $id)
    {
        $this->permit('product.view');

        $product = Product::with('variations.variation_location_details.location')
            ->findOrFail($id);

        return view('product.stock-history', ['product' => $product]);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * @return array<string, mixed>
     */
    protected function validateProduct(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:single,variable,combo',
            'unit_id' => 'required|integer|exists:units,id',
            'secondary_unit_id' => 'nullable|integer|exists:units,id',
            'sub_unit_ids' => 'nullable|array',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'sub_category_id' => 'nullable|integer|exists:categories,id',
            'tax' => 'nullable|integer|exists:tax_rates,id',
            'tax_type' => 'required|in:inclusive,exclusive',
            'sku' => 'nullable|string|max:255',
            'barcode_type' => 'required|string|max:20',
            'alert_quantity' => 'nullable|numeric|min:0',
            'weight' => 'nullable|string|max:50',
            'product_description' => 'nullable|string',
            'warranty_id' => 'nullable|integer|exists:warranties,id',
            'expiry_period' => 'nullable|numeric|min:0',
            'expiry_period_type' => 'nullable|in:days,months',
            'location_ids' => 'nullable|array',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function productAttributes(array $validated, Request $request, ?Product $product = null): array
    {
        $attributes = $validated;
        unset($attributes['location_ids']);

        $attributes['enable_stock'] = $request->boolean('enable_stock');
        $attributes['not_for_selling'] = $request->boolean('not_for_selling');
        $attributes['enable_sr_no'] = $request->boolean('enable_sr_no');
        $attributes['alert_quantity'] = $this->format->numUf($validated['alert_quantity'] ?? 0);

        if (empty($attributes['sku'])) {
            $attributes['sku'] = $this->products->generateSku();
        }

        if (empty($product)) {
            $attributes['created_by'] = auth()->id();
        }

        return $attributes;
    }

    /**
     * Create the variations for a newly-stored product.
     */
    protected function buildVariations(Product $product, Request $request): void
    {
        $prices = [
            'default_purchase_price' => $request->input('single_dpp'),
            'dpp_inc_tax' => $request->input('single_dpp_inc_tax'),
            'profit_percent' => $request->input('profit_percent'),
            'default_sell_price' => $request->input('single_dsp'),
        ];

        if ($product->type === 'variable') {
            $groups = [];

            foreach ($request->input('variations', []) as $group) {
                $variations = [];

                foreach ($group['variations'] ?? [] as $variation) {
                    if (empty($variation['name'])) {
                        continue;
                    }

                    $variations[] = [
                        'name' => $variation['name'],
                        'default_purchase_price' => $variation['dpp'] ?? 0,
                        'dpp_inc_tax' => $variation['dpp_inc_tax'] ?? 0,
                        'profit_percent' => $variation['profit_percent'] ?? 0,
                        'default_sell_price' => $variation['dsp'] ?? 0,
                    ];
                }

                if (! empty($variations)) {
                    $groups[] = [
                        'name' => $group['name'] ?? __('lang_v1.variation'),
                        'variation_template_id' => $group['template_id'] ?? null,
                        'variations' => $variations,
                    ];
                }
            }

            $this->products->createVariableVariations($product, $groups);

            return;
        }

        if ($product->type === 'combo') {
            $components = collect($request->input('combo', []))
                ->filter(fn ($c) => ! empty($c['variation_id']) && ! empty($c['quantity']))
                ->values()
                ->all();

            $this->products->createComboVariation($product, $components, $prices);

            return;
        }

        $this->products->createSingleVariation($product, $prices);
    }

    /**
     * Update prices on existing variations (never add/remove them here —
     * that would orphan FIFO lots).
     */
    protected function updateVariationPrices(Product $product, Request $request): void
    {
        foreach ($request->input('variation_prices', []) as $variationId => $prices) {
            $variation = $product->variations()->find($variationId);

            if (empty($variation)) {
                continue;
            }

            $variation->fill($this->products->normalisePrices($product, $prices));
            $variation->save();
        }

        // Single products keep their prices on the form's top-level fields.
        if ($product->type === 'single' && $request->filled('single_dsp')) {
            $variation = $product->variations()->first();

            if (! empty($variation)) {
                $variation->fill($this->products->normalisePrices($product, [
                    'default_purchase_price' => $request->input('single_dpp'),
                    'dpp_inc_tax' => $request->input('single_dpp_inc_tax'),
                    'profit_percent' => $request->input('profit_percent'),
                    'default_sell_price' => $request->input('single_dsp'),
                ]))->save();
            }
        }
    }

    protected function syncLocations(Product $product, Request $request): void
    {
        // An empty selection means "available everywhere".
        $product->product_locations()->sync($request->input('location_ids', []));
    }

    /**
     * Total stock per product id.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    protected function stockTotals(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return \App\Models\VariationLocationDetails::whereIn('product_id', $productIds)
            ->selectRaw('product_id, SUM(qty_available) AS total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(): array
    {
        return [
            'units' => Unit::forDropdown(),
            'subUnits' => Unit::forDropdown(true),
            'brands' => ['' => __('lang_v1.none')] + Brands::forDropdown(),
            'categories' => ['' => __('lang_v1.none')] + Category::forDropdown(),
            'taxes' => ['' => __('lang_v1.none')] + TaxRate::forDropdown(),
            'warranties' => ['' => __('lang_v1.none')] + Warranty::forDropdown(),
            'barcodeTypes' => array_combine(
                \App\Support\TransactionTypes::barcodeTypes(),
                \App\Support\TransactionTypes::barcodeTypes()
            ),
            'types' => Product::types(),
            'variationTemplates' => VariationTemplate::forDropdown(),
            'locations' => BusinessLocation::forDropdown(),
        ];
    }
}
