<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Support\Permissions;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Roles and their permissions.
 *
 * Not a {@see Concerns\SimpleCrudController}: a role is a name plus a set of
 * permissions, and the permission set is the whole screen — a grid of grouped
 * checkboxes ({@see Permissions::grouped()}) filtered to the tenant's enabled
 * modules. That is bespoke enough that riding the generic form buys nothing.
 *
 * Three tenancy rules run through every action:
 *
 * - Names are namespaced per business (`Manager#3`), because the spatie `roles`
 *   table is globally unique on (name, guard). The suffix is added on write and
 *   stripped for display by {@see Role::getDisplayNameAttribute()}; the user only
 *   ever sees "Manager". Uniqueness is validated on the *display* name within the
 *   tenant, so two businesses may both have a "Manager".
 * - The two default roles (`Admin`, `Cashier`, `is_default = 1`) are seeded with
 *   every business and cannot be renamed or deleted — Admin especially, since
 *   {@see \App\Models\User::isAdmin()} short-circuits every permission check on
 *   the literal role name.
 * - Admin holds NO explicit permissions by design, so its edit screen is
 *   read-only: ticking boxes for it would be theatre.
 */
class RoleController extends Controller
{
    public function index(Request $request)
    {
        $this->permit('roles.view');

        $roles = Role::forBusiness()
            // Counted, not loaded: the index prints how many permissions a role
            // holds and never reads one. `permissions` absent here was a
            // LazyLoadingViolationException the moment a tenant had two roles —
            // which every tenant does, since Admin and Cashier are both seeded.
            ->withCount(['users', 'permissions'])
            ->when($request->filled('search'), function ($query) use ($request) {
                // The stored name carries the #id suffix; matching the raw term
                // against it still finds "Manager" when the user types "Man".
                $query->where('name', 'like', '%'.$request->string('search').'%');
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('role.index', [
            'roles' => $roles,
            'canCreate' => $this->allows('roles.create'),
            'canUpdate' => $this->allows('roles.update'),
            'canDelete' => $this->allows('roles.delete'),
        ]);
    }

    public function create()
    {
        $this->permit('roles.create');

        return view('role.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->permit('roles.create');

        $validated = $this->validateRole($request);

        try {
            DB::transaction(function () use ($validated, $request) {
                $role = Role::create([
                    'name' => Role::nameFor($validated['name'], Tenancy::id()),
                    'business_id' => Tenancy::id(),
                    'is_default' => false,
                    'guard_name' => 'web',
                ]);

                $role->syncPermissions($this->selectedPermissions($request));
            });

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $this->backToIndex('roles.index', $output);
    }

    public function edit(int $id)
    {
        $this->permit('roles.update');

        $role = Role::forBusiness()->findOrFail($id);

        return view('role.edit', [
            'role' => $role,
            // Admin carries no explicit permissions and needs none — its screen
            // is a read-only note rather than an editable grid.
            'isAdmin' => $role->display_name === 'Admin',
            'assigned' => $role->permissions->pluck('name')->all(),
        ] + $this->formData());
    }

    public function update(Request $request, int $id)
    {
        $this->permit('roles.update');

        $role = Role::forBusiness()->findOrFail($id);

        // A default role's name is load-bearing (isAdmin keys off "Admin"), so it
        // is fixed; only its permissions — and only for Cashier — may move.
        if ($role->is_default) {
            $validated = ['name' => $role->display_name];
        } else {
            $validated = $this->validateRole($request, $role);
        }

        try {
            DB::transaction(function () use ($role, $validated, $request) {
                if (! $role->is_default) {
                    $role->update(['name' => Role::nameFor($validated['name'], Tenancy::id())]);
                }

                // Admin's permission set is meaningless (it bypasses every check),
                // so it is never rewritten.
                if ($role->display_name !== 'Admin') {
                    $role->syncPermissions($this->selectedPermissions($request));
                }
            });

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $this->backToIndex('roles.index', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->permit('roles.delete');

        try {
            $role = Role::forBusiness()->findOrFail($id);

            if ($role->is_default) {
                $output = ['success' => 0, 'msg' => __('lang_v1.cannot_delete_default_role')];
            } elseif ($role->users()->exists()) {
                $output = ['success' => 0, 'msg' => __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.users')])];
            } else {
                DB::transaction(fn () => $role->delete());
                $output = $this->ok(__('lang_v1.deleted_successfully'));
            }
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('roles.index', $output);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * @return array<string, mixed>
     */
    protected function formData(): array
    {
        return [
            'permissionGroups' => $this->visiblePermissionGroups(),
        ];
    }

    /**
     * Validate the submitted role name and return it in display form.
     *
     * The name needs care in two places. The column holds `Manager#3`, so a
     * plain `unique:roles,name` on what the user typed ("Manager") would compare
     * against a value that never matches and let duplicates through — the check
     * has to be made against {@see Role::nameFor()}. And the suffix is stripped
     * from the input first, so someone typing `Manager#9` cannot hand-craft a
     * role that belongs to another tenant's namespace.
     *
     * @return array{name: string}
     */
    protected function validateRole(Request $request, ?Role $role = null): array
    {
        $display = $this->stripSuffix((string) $request->input('name', ''));

        // Validate the cleaned value, so the error and the old() value both show
        // what will actually be saved.
        $request->merge(['name' => $display]);

        $request->validate([
            'name' => [
                // Capped below the column's 255 to leave room for the `#<id>`.
                'required', 'string', 'max:200',
                function (string $attribute, mixed $value, \Closure $fail) use ($role) {
                    // Reserved case-insensitively: a second "admin" is not a
                    // privilege escalation (isAdmin() matches `Admin#<id>`
                    // exactly) but it is indistinguishable on screen from the
                    // role that is, which is its own kind of dangerous.
                    if (in_array(mb_strtolower((string) $value), ['admin', 'cashier'], true)) {
                        $fail(__('lang_v1.role_name_reserved'));

                        return;
                    }

                    $taken = Role::where('name', Role::nameFor((string) $value, Tenancy::id()))
                        ->when($role, fn ($query) => $query->whereKeyNot($role->id))
                        ->exists();

                    if ($taken) {
                        $fail(__('validation.unique', ['attribute' => __('lang_v1.role_name')]));
                    }
                },
            ],
        ]);

        return ['name' => $display];
    }

    /**
     * The permission grid, minus any group whose module the tenant has not
     * enabled — a role editor should not offer `essentials.*` to a business
     * without the HR module.
     *
     * @return array<string, array<int, string>>
     */
    protected function visiblePermissionGroups(): array
    {
        $enabled = (array) session('business.enabled_modules');
        $moduleMap = Permissions::moduleMap();

        return collect(Permissions::grouped())
            ->map(function (array $permissions) use ($enabled, $moduleMap) {
                return array_values(array_filter($permissions, function (string $permission) use ($enabled, $moduleMap) {
                    foreach ($moduleMap as $prefix => $module) {
                        if (str_starts_with($permission, $prefix)) {
                            return in_array($module, $enabled, true);
                        }
                    }

                    return true;
                }));
            })
            ->filter(fn (array $permissions) => ! empty($permissions))
            ->all();
    }

    /**
     * The permissions ticked on the form, intersected with what the tenant is
     * actually allowed to grant — a hand-crafted POST cannot enable a module
     * permission the business does not have.
     *
     * @return array<int, string>
     */
    protected function selectedPermissions(Request $request): array
    {
        $allowed = array_merge(...array_values($this->visiblePermissionGroups()));

        return array_values(array_intersect(
            (array) $request->input('permissions', []),
            $allowed
        ));
    }

    protected function stripSuffix(string $name): string
    {
        return explode('#', trim($name))[0];
    }
}
