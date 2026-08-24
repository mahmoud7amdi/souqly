<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Base for the many settings screens that are a plain tenant-scoped CRUD over
 * one table (brands, units, tax rates, warranties, price groups, …).
 *
 * The source project had ~15 near-identical 250-line controllers for these.
 * Subclasses here declare what differs — model, permissions, view folder,
 * validation, columns — and inherit the behaviour.
 */
abstract class SimpleCrudController extends Controller
{
    /** Fully-qualified model class. */
    protected string $model;

    /** Blade folder holding index/create/edit. */
    protected string $viewPath;

    /** Route name prefix, e.g. `brands` for brands.index. */
    protected string $routePrefix;

    /** Permission prefix, e.g. `brand` → brand.view/create/update/delete. */
    protected string $permission;

    /** Translation key for the singular record name. */
    protected string $label = 'lang_v1.record';

    /** Columns rendered in the index table: column => translation key. */
    protected array $columns = [];

    /** Eager loads for the index query. */
    protected array $with = [];

    /**
     * @return array<string, mixed> validation rules
     */
    abstract protected function rules(Request $request, ?Model $record = null): array;

    /* ================================================================
     | CRUD
     ================================================================ */

    public function index(Request $request)
    {
        $this->permit($this->ability('view'));

        $records = $this->indexQuery()
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(function (Builder $q) use ($term) {
                    foreach ($this->searchableColumns() as $column) {
                        $q->orWhere($column, 'like', $term);
                    }
                });
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view($this->viewPath.'.index', [
            'records' => $records,
            'columns' => $this->columns,
            'routePrefix' => $this->routePrefix,
            'label' => __($this->label),
            'canCreate' => $this->allows($this->ability('create')),
            'canUpdate' => $this->allows($this->ability('update')),
            'canDelete' => $this->allows($this->ability('delete')),
        ] + $this->indexViewData());
    }

    public function create()
    {
        $this->permit($this->ability('create'));

        return view($this->viewPath.'.create', [
            'routePrefix' => $this->routePrefix,
            'label' => __($this->label),
        ] + $this->formViewData());
    }

    public function store(Request $request)
    {
        $this->permit($this->ability('create'));

        $validated = $request->validate($this->rules($request));

        try {
            DB::transaction(function () use ($validated, $request) {
                $record = $this->model::create($this->prepare($validated, $request));
                $this->afterSave($record, $request, true);
            });

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $this->backToIndex($this->routePrefix.'.index', $output);
    }

    public function edit(int $id)
    {
        $this->permit($this->ability('update'));

        $record = $this->findRecord($id);

        return view($this->viewPath.'.edit', [
            'record' => $record,
            'routePrefix' => $this->routePrefix,
            'label' => __($this->label),
        ] + $this->formViewData($record));
    }

    public function update(Request $request, int $id)
    {
        $this->permit($this->ability('update'));

        $record = $this->findRecord($id);

        $validated = $request->validate($this->rules($request, $record));

        try {
            DB::transaction(function () use ($record, $validated, $request) {
                $record->update($this->prepare($validated, $request, $record));
                $this->afterSave($record, $request, false);
            });

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $this->backToIndex($this->routePrefix.'.index', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->permit($this->ability('delete'));

        try {
            $record = $this->findRecord($id);

            $blocker = $this->deletionBlockedBy($record);

            if (! empty($blocker)) {
                $output = ['success' => 0, 'msg' => $blocker];
            } else {
                DB::transaction(fn () => $record->delete());
                $output = $this->ok(__('lang_v1.deleted_successfully'));
            }
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex($this->routePrefix.'.index', $output);
    }

    /* ================================================================
     | Extension points
     ================================================================ */

    /**
     * The permission name guarding one action.
     *
     * Most catalogue tables carry a four-verb group — `brand.view`,
     * `brand.create`, `brand.update`, `brand.delete` — and the default
     * concatenation is exactly right for them.
     *
     * The settings tables do not. The source system gates invoice schemes,
     * invoice layouts, barcodes and printers behind a *single* flat permission
     * each (`invoice_settings.access`, `barcode_settings.access`,
     * `access_printers`), and those names are preserved byte-for-byte because
     * the sidebar and every `can()` check key off them — see
     * {@see \App\Support\Permissions}. A subclass with a flat permission
     * overrides this to return it for every action.
     *
     * Routing all six checks through one method is what makes that possible
     * without reimplementing the CRUD verbs: the alternative is a subclass
     * that checks `invoice_settings.view`, a permission nobody holds. That
     * failure is invisible under an admin account, because `Gate::before()`
     * bypasses every check, and a silent lockout for everyone else — the same
     * trap {@see \App\Http\Controllers\ExpenseCategoryController} documents.
     *
     * @param  string  $action  one of view, create, update, delete
     */
    protected function ability(string $action): string
    {
        return $this->permission.'.'.$action;
    }

    /**
     * Load the record an edit/update/delete acts on.
     *
     * The default trusts the global tenant scope on the model: a subclass whose
     * table is NOT scoped that way — {@see \App\Models\Barcode}, which omits
     * `BelongsToBusiness` so it can surface shared global presets alongside a
     * tenant's own rows — overrides this to constrain writes to the caller's own
     * rows. A global preset (`business_id IS NULL`) then falls outside the query
     * and returns 404, so no tenant can edit or delete a row every tenant sees.
     *
     * Reads are wider than writes on purpose: {@see indexQuery()} lists own +
     * global, while this narrows the mutating verbs to own-only.
     */
    protected function findRecord(int $id): Model
    {
        return $this->model::findOrFail($id);
    }

    protected function indexQuery(): Builder
    {
        return $this->model::query()->with($this->with);
    }

    /**
     * Columns the index search box looks at.
     *
     * @return array<int, string>
     */
    protected function searchableColumns(): array
    {
        return ['name'];
    }

    /**
     * Map validated input onto model attributes.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function prepare(array $validated, Request $request, ?Model $record = null): array
    {
        if (in_array('created_by', $this->fillableSystemColumns(), true) && empty($record)) {
            $validated['created_by'] = auth()->id();
        }

        return $validated;
    }

    /**
     * Columns set automatically rather than from user input.
     *
     * @return array<int, string>
     */
    protected function fillableSystemColumns(): array
    {
        return ['created_by'];
    }

    protected function afterSave(Model $record, Request $request, bool $created): void
    {
        // no-op
    }

    /**
     * Reason the record cannot be deleted, or null when it can.
     */
    protected function deletionBlockedBy(Model $record): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function indexViewData(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formViewData(?Model $record = null): array
    {
        return [];
    }
}
