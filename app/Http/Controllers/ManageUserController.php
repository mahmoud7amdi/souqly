<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Staff accounts — the tenant's users, their role and their location access.
 *
 * Separate from {@see UserController}, which is the signed-in user's own
 * profile. This one manages *other* people, and that difference is the whole
 * reason it is not a {@see Concerns\SimpleCrudController}:
 *
 * - `users` deliberately has no `BelongsToBusiness` global scope, because login
 *   must find a user before a tenant exists ({@see User::forDropdown()} spells
 *   this out). Every query here therefore filters `business_id` by hand, and
 *   `business_id` itself is never taken from input.
 * - A password is hashed and is required on create but optional on update, so
 *   the validated array cannot be passed straight to `update()`.
 * - Role and location access are not columns. The role is a spatie relation and
 *   the locations are per-location permissions (`location.<id>`), both synced
 *   after the row is written.
 *
 * Three lockout guards run on the destructive paths, because every one of them
 * is a way to leave a business with no way back in: you cannot delete your own
 * account, you cannot delete or demote the last Admin, and a user of another
 * business is simply not found.
 */
class ManageUserController extends Controller
{
    public function index(Request $request)
    {
        $this->permit('user.view');

        $users = User::query()
            ->where('business_id', Tenancy::id())
            ->user()
            ->with('roles')
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(function ($q) use ($term) {
                    $q->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('username', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate(25)
            ->withQueryString();

        return view('manage_user.index', [
            'users' => $users,
            'canCreate' => $this->allows('user.create'),
            'canUpdate' => $this->allows('user.update'),
            'canDelete' => $this->allows('user.delete'),
        ]);
    }

    public function create()
    {
        $this->permit('user.create');

        return view('manage_user.create', [
            'user' => null,
            'assignedRole' => null,
            'assignedLocations' => [],
            'allLocations' => false,
        ] + $this->formData());
    }

    public function store(Request $request)
    {
        $this->permit('user.create');

        $validated = $this->validateUser($request);

        try {
            DB::transaction(function () use ($validated, $request) {
                $user = User::create([
                    'user_type' => 'user',
                    'business_id' => Tenancy::id(),
                    'surname' => $validated['surname'],
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'username' => $validated['username'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'language' => $validated['language'],
                    'contact_no' => $validated['contact_no'],
                    'address' => $validated['address'],
                    'status' => $validated['status'],
                    'allow_login' => $validated['allow_login'],
                    'is_cmmsn_agnt' => $validated['is_cmmsn_agnt'],
                    'cmmsn_percent' => $validated['cmmsn_percent'] ?? 0,
                    'max_sales_discount_percent' => $validated['max_sales_discount_percent'],
                ]);

                $this->syncRole($user, $request);
                $this->syncLocationAccess($user, $request);
            });

            $output = $this->ok(__('lang_v1.added_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $this->backToIndex('users.index', $output);
    }

    public function edit(int $id)
    {
        $this->permit('user.update');

        $user = $this->findUser($id);
        $held = $user->getDirectPermissions()->pluck('name');

        return view('manage_user.edit', [
            'user' => $user,
            'assignedRole' => $user->roles->first()?->id,
            // Direct permissions, not the role's: location access is granted to
            // the person, so a role change never silently moves which tills
            // they can stand at.
            'assignedLocations' => $held->filter(fn (string $p) => str_starts_with($p, 'location.'))
                ->map(fn (string $p) => (int) substr($p, strlen('location.')))
                ->values()
                ->all(),
            'allLocations' => $held->contains('access_all_locations'),
        ] + $this->formData());
    }

    public function update(Request $request, int $id)
    {
        $this->permit('user.update');

        $user = $this->findUser($id);
        $validated = $this->validateUser($request, $user);

        try {
            $blocker = $this->demotionBlockedBy($user, $request);

            if (! empty($blocker)) {
                return $this->backToIndex('users.index', ['success' => 0, 'msg' => $blocker]);
            }

            DB::transaction(function () use ($user, $validated, $request) {
                // `business_id`, `user_type` and `username` are all absent here on
                // purpose: the first two are never editable, and a username is an
                // identity people log in with — renaming it is a support action,
                // not a settings toggle.
                $user->fill([
                    'surname' => $validated['surname'],
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'email' => $validated['email'],
                    'language' => $validated['language'],
                    'contact_no' => $validated['contact_no'],
                    'address' => $validated['address'],
                    'status' => $validated['status'],
                    'allow_login' => $validated['allow_login'],
                    'is_cmmsn_agnt' => $validated['is_cmmsn_agnt'],
                    'cmmsn_percent' => $validated['cmmsn_percent'] ?? 0,
                    'max_sales_discount_percent' => $validated['max_sales_discount_percent'],
                ]);

                // Blank means "leave it alone" — the field is not a way to read
                // the current password, so it always renders empty.
                if (filled($validated['password'] ?? null)) {
                    $user->password = Hash::make($validated['password']);
                }

                $user->save();

                $this->syncRole($user, $request);
                $this->syncLocationAccess($user, $request);
            });

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $this->backToIndex('users.index', $output);
    }

    public function destroy(Request $request, int $id)
    {
        $this->permit('user.delete');

        try {
            $user = $this->findUser($id);

            if ($user->id === auth()->id()) {
                $output = ['success' => 0, 'msg' => __('lang_v1.cannot_delete_own_account')];
            } elseif ($this->isLastAdmin($user)) {
                $output = ['success' => 0, 'msg' => __('lang_v1.cannot_delete_last_admin')];
            } else {
                DB::transaction(fn () => $user->delete());
                $output = $this->ok(__('lang_v1.deleted_successfully'));
            }
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return $request->ajax()
            ? response()->json($output)
            : $this->backToIndex('users.index', $output);
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
            'roles' => Role::forDropdown(),
            // Every location of the tenant, including inactive ones — not
            // BusinessLocation::forDropdown(), which hides those. A user already
            // granted a location that was later deactivated must still see it
            // ticked here, or the next save of an unrelated field would quietly
            // strip access that someone deliberately gave.
            'locations' => BusinessLocation::query()->orderBy('name')->pluck('name', 'id')->all(),
            'languages' => collect(config('constants.langs'))
                ->mapWithKeys(fn (array $lang, string $code) => [$code => $lang['full_name']])
                ->all(),
        ];
    }

    /**
     * The tenant's own user, or 404.
     *
     * `users` has no tenant global scope, so this filter is the only thing
     * standing between one shop's settings screen and another shop's staff.
     */
    protected function findUser(int $id): User
    {
        return User::query()
            ->where('business_id', Tenancy::id())
            ->user()
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateUser(Request $request, ?User $user = null): array
    {
        $rules = [
            'surname' => 'nullable|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at')
                    ->when($user, fn ($rule) => $rule->ignore($user->id)),
            ],
            'language' => 'required|string|in:'.implode(',', array_keys(config('constants.langs'))),
            'contact_no' => 'nullable|string|max:15',
            'address' => 'nullable|string|max:1000',
            'status' => 'required|in:active,inactive,terminated',
            'max_sales_discount_percent' => 'nullable|numeric|min:0|max:100',
            'cmmsn_percent' => 'nullable|numeric|min:0|max:100',
            'role_id' => [
                'required', 'integer',
                // Scoped to this business, so a crafted POST cannot hand a user
                // another tenant's role — which, with a role named `Admin#other`,
                // would be a cross-tenant privilege grant.
                Rule::exists('roles', 'id')->where('business_id', Tenancy::id()),
            ],
            'location_ids' => 'nullable|array',
            'location_ids.*' => [
                'integer',
                Rule::exists('business_locations', 'id')->where('business_id', Tenancy::id()),
            ],
        ];

        if (empty($user)) {
            // `username` is globally unique, not per-tenant: it is the login
            // identifier and two businesses cannot share one.
            $rules['username'] = ['required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('users', 'username')->whereNull('deleted_at')];
            $rules['password'] = ['required', 'confirmed', Password::min(8)];
        } else {
            $rules['password'] = ['nullable', 'confirmed', Password::min(8)];
        }

        $validated = $request->validate($rules);

        // Checkboxes are absent from the payload when unticked, so they are read
        // from the request rather than the validated array.
        $validated['allow_login'] = $request->boolean('allow_login');
        $validated['is_cmmsn_agnt'] = $request->boolean('is_cmmsn_agnt');

        // Every optional text field must exist as a key: both callers hand the
        // array straight to fill()/create(), and a missing key would leave a
        // stale value in place on update instead of clearing it.
        foreach (['surname', 'last_name', 'email', 'contact_no', 'address',
            'max_sales_discount_percent', 'password'] as $optional) {
            $validated[$optional] = $validated[$optional] ?? null;
        }

        return $validated;
    }

    protected function syncRole(User $user, Request $request): void
    {
        $role = Role::forBusiness()->find($request->integer('role_id'));

        if ($role) {
            $user->syncRoles([$role]);
        }
    }

    /**
     * Grant location access as direct permissions.
     *
     * "All locations" and an explicit list are mutually exclusive on purpose:
     * holding both would mean the list is decorative, and a later location would
     * silently become visible to someone who was only ever granted two.
     */
    protected function syncLocationAccess(User $user, Request $request): void
    {
        $keep = $user->getDirectPermissions()
            ->pluck('name')
            ->reject(fn (string $p) => $p === 'access_all_locations' || str_starts_with($p, 'location.'))
            ->values()
            ->all();

        if ($request->boolean('access_all_locations')) {
            $user->syncPermissions(array_merge($keep, ['access_all_locations']));

            return;
        }

        $granted = BusinessLocation::query()
            ->whereIn('id', (array) $request->input('location_ids', []))
            ->pluck('id')
            ->map(fn (int $id) => Permissions::forLocation($id))
            ->all();

        $user->syncPermissions(array_merge($keep, $granted));
    }

    /**
     * Reason this update must not go through, or null.
     *
     * A business with no Admin has nobody who can create one, so the last Admin
     * may neither be moved to another role nor barred from logging in. The check
     * is skipped whenever the submitted role *is* Admin, so an ordinary edit to
     * the only owner's name still saves.
     */
    protected function demotionBlockedBy(User $user, Request $request): ?string
    {
        if (! $this->isLastAdmin($user)) {
            return null;
        }

        $target = Role::forBusiness()->find($request->integer('role_id'));

        if ($target?->display_name !== 'Admin') {
            return __('lang_v1.cannot_demote_last_admin');
        }

        if (! $request->boolean('allow_login') || $request->input('status') !== 'active') {
            return __('lang_v1.cannot_disable_last_admin');
        }

        return null;
    }

    /**
     * Is this the only user of the tenant holding the Admin role?
     */
    protected function isLastAdmin(User $user): bool
    {
        $adminRole = Role::forBusiness()
            ->where('name', Role::nameFor('Admin', (int) Tenancy::id()))
            ->first();

        if (! $adminRole || ! $user->hasRole($adminRole)) {
            return false;
        }

        return $adminRole->users()
            ->where('users.business_id', Tenancy::id())
            ->whereKeyNot($user->id)
            ->doesntExist();
    }
}
