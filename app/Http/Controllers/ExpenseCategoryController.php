<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use App\Models\Transaction;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The expense category catalogue.
 *
 * Written standalone rather than on {@see SimpleCrudController} for two reasons,
 * both of which would have needed working around anyway:
 *
 *   - **Permissions don't fit the pattern.** The base derives `<name>.view`,
 *     `<name>.create` and so on. Expenses use `all_expense.access`,
 *     `view_own_expense`, `expense.add`, `expense.edit`, `expense.delete` — an
 *     `expense.view` ability does not exist. Subclassing would check a permission
 *     nobody holds, which is invisible under an admin account (Gate::before
 *     bypasses everything) and a silent lockout for a cashier.
 *   - **A category is a two-level tree.** The generic `crud/*` views render a flat
 *     name list; this screen has to show children under their parent and offer a
 *     `code` column, so the views are bespoke regardless.
 *
 * Depth is capped at two on purpose. `expenses` has exactly two columns for it
 * (`expense_category_id`, `expense_sub_category_id`), so a third level would have
 * nowhere to be recorded — better to refuse it at the form than to accept a
 * category that reporting cannot see.
 */
class ExpenseCategoryController extends Controller
{
    public function index()
    {
        $this->permit('expense.add', 'all_expense.access');

        $parents = ExpenseCategory::with(['sub_categories' => fn ($q) => $q->orderBy('name')])
            ->onlyParent()
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('expense_category.index', [
            'parents' => $parents,
            'usage' => $this->usageCounts($parents),
        ]);
    }

    public function create()
    {
        $this->permit('expense.add');

        return view('expense_category.create', [
            'category' => null,
            'parents' => ['' => __('lang_v1.main_category')] + ExpenseCategory::forDropdown(),
        ]);
    }

    public function store(Request $request)
    {
        $this->permit('expense.add');

        $validated = $this->validateCategory($request);

        try {
            ExpenseCategory::create($validated + ['business_id' => Tenancy::id()]);

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return $this->backToIndex('expense-categories.index', $output);
    }

    public function edit(int $id)
    {
        $this->permit('expense.edit');

        // Counted here rather than in the view: the form disables the parent
        // picker for a category that already has children (the same rule
        // `update()` enforces), and a view has no business running that query.
        $category = ExpenseCategory::withCount('sub_categories')->findOrFail($id);

        // A category cannot be its own parent.
        $parents = ExpenseCategory::forDropdown();
        unset($parents[$category->id]);

        return view('expense_category.edit', [
            'category' => $category,
            'parents' => ['' => __('lang_v1.main_category')] + $parents,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->permit('expense.edit');

        $category = ExpenseCategory::withCount('sub_categories')->findOrFail($id);

        $validated = $this->validateCategory($request, $category);

        if (! empty($validated['parent_id']) && $category->sub_categories_count > 0) {
            return back()->withInput()->with('status', $this->failed(
                null, __('lang_v1.cannot_nest_category_with_children')
            ));
        }

        try {
            $category->update($validated);

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return $this->backToIndex('expense-categories.index', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->permit('expense.delete');

        $category = ExpenseCategory::withCount('sub_categories')->findOrFail($id);

        if ($category->sub_categories_count > 0) {
            $output = $this->failed(null, __('lang_v1.category_has_sub_categories'));

            return $request->ajax()
                ? response()->json($output)
                : $this->backToIndex('expense-categories.index', $output);
        }

        /*
         * Refused rather than nulled: an expense whose category vanished is a
         * hole in every report that groups by category, and the person deleting
         * a category is rarely the person who will later wonder where the money
         * went. Reassign the expenses first.
         */
        if ($this->usageFor($category->id) > 0) {
            $output = $this->failed(null, __('lang_v1.category_is_in_use'));

            return $request->ajax()
                ? response()->json($output)
                : $this->backToIndex('expense-categories.index', $output);
        }

        try {
            $category->delete();

            $output = $this->ok(__('lang_v1.deleted_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('expense-categories.index', $output);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * @return array<string, mixed>
     */
    protected function validateCategory(Request $request, ?ExpenseCategory $category = null): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'code' => [
                'nullable', 'string', 'max:191',
                Rule::unique('expense_categories', 'code')
                    ->where('business_id', Tenancy::id())
                    ->whereNull('deleted_at')
                    ->ignore($category?->id),
            ],
            'parent_id' => 'nullable|integer|exists:expense_categories,id',
        ]);

        // Two levels only: a parent that is itself a child cannot take children.
        if (! empty($validated['parent_id'])) {
            $parentIsChild = ExpenseCategory::where('id', $validated['parent_id'])
                ->whereNotNull('parent_id')
                ->exists();

            if ($parentIsChild) {
                abort(422, __('lang_v1.cannot_nest_under_sub_category'));
            }
        }

        $validated['parent_id'] = $validated['parent_id'] ?: null;
        $validated['code'] = $validated['code'] ?: null;

        return $validated;
    }

    /**
     * How many expenses reference each category on the page, in one query.
     *
     * Both columns are counted together: to the person reading the list, "used"
     * means "an expense points at this", and which of the two columns holds the
     * pointer is an implementation detail of the two-level shape.
     *
     * @return array<int, int>
     */
    protected function usageCounts(\Illuminate\Contracts\Pagination\Paginator $parents): array
    {
        $ids = collect($parents->items())
            ->flatMap(fn ($parent) => [$parent->id, ...$parent->sub_categories->pluck('id')])
            ->all();

        if (empty($ids)) {
            return [];
        }

        $counts = [];

        foreach (['expense_category_id', 'expense_sub_category_id'] as $column) {
            $rows = Transaction::whereIn($column, $ids)
                ->selectRaw("$column AS category_id, COUNT(*) AS total")
                ->groupBy($column)
                ->pluck('total', 'category_id');

            foreach ($rows as $categoryId => $total) {
                $counts[(int) $categoryId] = ($counts[(int) $categoryId] ?? 0) + (int) $total;
            }
        }

        return $counts;
    }

    /**
     * Whether any expense still points at this category.
     *
     * The two columns are OR'd inside a closure, not at the top level: the
     * tenancy scope is a `where` on the same query, and an ungrouped `orWhere`
     * would let it be satisfied by the OR branch alone — which is another
     * tenant's rows deciding whether this one may delete a category.
     */
    protected function usageFor(int $categoryId): int
    {
        return Transaction::where(fn ($q) => $q->where('expense_category_id', $categoryId)
            ->orWhere('expense_sub_category_id', $categoryId))
            ->count();
    }
}
