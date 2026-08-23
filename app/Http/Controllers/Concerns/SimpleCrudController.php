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
        $this->permit($this->permission.'.view');

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
            'canCreate' => $this->allows($this->permission.'.create'),
            'canUpdate' => $this->allows($this->permission.'.update'),
            'canDelete' => $this->allows($this->permission.'.delete'),
        ] + $this->indexViewData());
    }

    public function create()
    {
        $this->permit($this->permission.'.create');

        return view($this->viewPath.'.create', [
            'routePrefix' => $this->routePrefix,
            'label' => __($this->label),
        ] + $this->formViewData());
    }

    public function store(Request $request)
    {
        $this->permit($this->permission.'.create');

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
        $this->permit($this->permission.'.update');

        $record = $this->model::findOrFail($id);

        return view($this->viewPath.'.edit', [
            'record' => $record,
            'routePrefix' => $this->routePrefix,
            'label' => __($this->label),
        ] + $this->formViewData($record));
    }

    public function update(Request $request, int $id)
    {
        $this->permit($this->permission.'.update');

        $record = $this->model::findOrFail($id);

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
        $this->permit($this->permission.'.delete');

        try {
            $record = $this->model::findOrFail($id);

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
