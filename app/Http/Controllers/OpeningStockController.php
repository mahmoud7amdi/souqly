<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\FormattingService;
use App\Services\OpeningStockService;
use App\Support\TenantRules;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;

/**
 * Opening stock — one screen per (product, location) pairing.
 *
 * Shaped as a form over an existing fact rather than as a resource with a
 * create/edit split: "what this product's opening quantity is at this shop" is a
 * single statement that has either been made or not, so there is one editor and
 * it opens whether or not a document exists yet. See
 * {@see OpeningStockService} for why that document has to exist at all.
 *
 * Location travels in the query string, not the path. It is a key to the
 * document, but it is also a thing the person switches while working — checking
 * the same product across three shops — and putting it in the path would mean a
 * redirect on every switch and three URLs for what feels like one screen.
 */
class OpeningStockController extends Controller
{
    public function __construct(
        protected OpeningStockService $opening,
        protected FormattingService $format,
    ) {}

    /* ================================================================
     | Listing
     ================================================================ */

    public function index(Request $request)
    {
        $this->permit('product.opening_stock');

        $locations = BusinessLocation::forDropdown();
        $locationId = $this->resolveLocation($request, $locations);

        $products = Product::query()
            ->with(['unit', 'category', 'brand'])
            // Services and untracked products have no opening position to state,
            // so they are not listed rather than being listed and refused.
            ->where('enable_stock', 1)
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('sku', 'like', $term));
            })
            ->when($request->filled('category_id'),
                fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($locationId, fn ($q) => $q->forLocation($locationId))
            ->when($request->filled('recorded'), function ($query) use ($request, $locationId) {
                $exists = fn ($q) => $q->whereExists(
                    fn ($sub) => $sub->selectRaw(1)
                        ->from('transactions')
                        ->whereColumn('transactions.opening_stock_product_id', 'products.id')
                        ->where('transactions.type', TransactionTypes::OPENING_STOCK)
                        ->when($locationId, fn ($t) => $t->where('transactions.location_id', $locationId))
                );

                $request->string('recorded')->value() === 'yes'
                    ? $exists($query)
                    : $query->whereNot($exists);
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        // One query for the whole page rather than one per row: the listing only
        // needs a quantity and a value per product, and N+1 on a 25-row table of
        // documents-with-lines is the difference between one query and fifty.
        $summaries = $this->summaries($products->pluck('id')->all(), $locationId);

        return view('opening_stock.index', [
            'products' => $products,
            'summaries' => $summaries,
            'totals' => $this->listTotals($locationId),
            'locations' => $locations,
            'locationId' => $locationId,
            'categories' => ['' => __('lang_v1.all')] + Category::forDropdown(),
        ]);
    }

    /* ================================================================
     | Editor
     ================================================================ */

    public function edit(Request $request, int $productId)
    {
        $this->permit('product.opening_stock');

        $product = Product::with(['variations.product_variation', 'unit'])
            ->where('enable_stock', 1)
            ->findOrFail($productId);

        $locations = BusinessLocation::forDropdown();
        $locationId = $this->resolveLocation($request, $locations);

        if (empty($locationId)) {
            return $this->backToIndex('opening-stock.index', [
                'success' => 0,
                'msg' => __('lang_v1.no_permitted_location'),
            ]);
        }

        $document = $this->opening->forProduct($product, $locationId);

        return view('opening_stock.edit', [
            'product' => $product,
            'document' => $document,
            'lots' => optional($document)->purchase_lines?->keyBy('variation_id') ?? collect(),
            'locations' => $locations,
            'locationId' => $locationId,
        ]);
    }

    public function update(Request $request, int $productId)
    {
        $this->permit('product.opening_stock');

        $product = Product::with('variations')->where('enable_stock', 1)->findOrFail($productId);

        $validated = $request->validate([
            'location_id' => ['required', 'integer', TenantRules::location()],
            'transaction_date' => 'nullable|date',
            'quantities' => 'required|array',
            'quantities.*' => 'nullable|numeric|min:0',
            'prices' => 'nullable|array',
            'prices.*' => 'nullable|numeric|min:0',
        ]);

        $locationId = (int) $validated['location_id'];

        try {
            $this->opening->save(
                $product,
                $locationId,
                $validated['quantities'],
                $validated['prices'] ?? [],
                ! empty($validated['transaction_date'])
                    ? ($this->format->ufDate($validated['transaction_date'], true)
                        ?? $validated['transaction_date'])
                    : null
            );

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        /*
         * "Save & add group selling price" continues the chain the product create
         * form starts: product → opening position → per-group prices. The stock is
         * already committed by the time we get here, so the branch only chooses
         * where to land.
         *
         * `product.update` is checked rather than assumed. The group-price screen
         * calls `permit('product.update')`, and this screen only requires
         * `product.opening_stock` — so a user holding one and not the other would
         * otherwise be shown a 403 on top of a save that actually succeeded. The
         * button is hidden for them too; this is the same rule enforced twice,
         * because a hidden button is not a closed route.
         */
        if ($request->input('submit_type') === 'submit_n_add_selling_prices'
            && $this->allows('product.update')) {
            return redirect()
                ->route('products.addSellingPrices', $product->id)
                ->with('status', $output);
        }

        return redirect()
            ->route('opening-stock.index', ['location_id' => $locationId])
            ->with('status', $output);
    }

    public function destroy(Request $request, int $productId)
    {
        $this->permit('product.opening_stock');

        $product = Product::findOrFail($productId);
        $locationId = $request->integer('location_id');

        try {
            $this->opening->delete($product, $locationId);
            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('opening-stock.index', $output);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * The location being worked on: what was asked for if it is permitted, else
     * the first one the user may see.
     *
     * @param  array<int, string>  $locations
     */
    protected function resolveLocation(Request $request, array $locations): ?int
    {
        $requested = $request->integer('location_id');

        if ($requested && array_key_exists($requested, $locations)) {
            return $requested;
        }

        $first = array_key_first($locations);

        return $first === null ? null : (int) $first;
    }

    /**
     * Quantity and value of the opening position for a page of products.
     *
     * @param  array<int, int>  $productIds
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function summaries(array $productIds, ?int $locationId): \Illuminate\Support\Collection
    {
        if (empty($productIds) || empty($locationId)) {
            return collect();
        }

        return \App\Models\PurchaseLine::query()
            ->join('transactions as t', 't.id', '=', 'purchase_lines.transaction_id')
            ->where('t.type', TransactionTypes::OPENING_STOCK)
            ->where('t.location_id', $locationId)
            ->whereIn('purchase_lines.product_id', $productIds)
            ->groupBy('purchase_lines.product_id')
            ->selectRaw('purchase_lines.product_id,
                         min(t.id) as transaction_id,
                         min(t.transaction_date) as transaction_date,
                         sum(purchase_lines.quantity) as quantity,
                         sum(purchase_lines.quantity * purchase_lines.purchase_price_inc_tax) as value')
            ->get()
            ->keyBy('product_id');
    }

    /**
     * @return array<string, float|int>
     */
    protected function listTotals(?int $locationId): array
    {
        if (empty($locationId)) {
            return ['value' => 0.0, 'documents' => 0];
        }

        $query = Transaction::ofType(TransactionTypes::OPENING_STOCK)
            ->permittedLocations()
            ->where('location_id', $locationId);

        return [
            'value' => (float) $query->clone()->sum('final_total'),
            'documents' => $query->clone()->count(),
        ];
    }
}
