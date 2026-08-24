{{--
    Role form, shared by create and edit.

    The screen is really the permission grid: a role is a name plus a set of
    ticked abilities. Each group from Permissions::grouped() becomes a panel of
    checkboxes with a group-level "select all" toggle, because a manager role
    routinely wants "everything under sell" and ticking twenty boxes by hand is
    the fastest way to miss one.

    Expects: $permissionGroups (label => [permission names]), $assigned (names
    already held, empty on create), $isAdmin (edit only — Admin's grid is a
    read-only note), $role (edit only).
--}}
@php
    $role = $role ?? null;
    $assigned = $assigned ?? [];
    $isAdmin = $isAdmin ?? false;
    $isDefault = $role?->is_default ?? false;
@endphp

<div class="grid gap-6">
    <x-panel :title="__('lang_v1.role_details')" icon="key">
        <div class="form-grid">
            <div class="field sm:col-span-2">
                <label for="name" class="label label-required">{{ __('lang_v1.role_name') }}</label>
                <input id="name" name="name"
                       @class(['input', 'input-invalid' => $errors->has('name')])
                       value="{{ old('name', $role?->display_name ?? '') }}"
                       @readonly($isDefault) required>
                @if ($isDefault)
                    {{-- A default role's name is load-bearing — isAdmin() keys off
                         "Admin" literally — so it is shown but never editable. --}}
                    <p class="hint">{{ __('lang_v1.default_role_name_locked') }}</p>
                @endif
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </x-panel>

    @if ($isAdmin)
        <div class="alert-info">
            <x-nav-icon name="shield" :size="5"/>
            <div>
                <p class="font-medium">{{ __('lang_v1.admin_full_access_title') }}</p>
                <p class="text-sm">{{ __('lang_v1.admin_full_access_hint') }}</p>
            </div>
        </div>
    @else
        <x-panel :title="__('lang_v1.permissions')" icon="shield">
            <div class="grid gap-5">
                @foreach ($permissionGroups as $group => $permissions)
                    <div class="surface-quiet">
                        <div class="section-head">
                            <h4 class="font-semibold">{{ \App\Support\Permissions::groupLabel($group) }}</h4>
                            <label class="checkbox-row">
                                <input type="checkbox" class="checkbox" data-select-all="{{ $group }}">
                                <span class="checkbox-label text-sm">{{ __('lang_v1.select_all') }}</span>
                            </label>
                        </div>

                        <div class="mt-3 grid gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($permissions as $permission)
                                <label class="checkbox-row">
                                    <input type="checkbox" name="permissions[]" value="{{ $permission }}"
                                           class="checkbox" data-group="{{ $group }}"
                                           @checked(in_array($permission, old('permissions', $assigned), true))>
                                    <span class="checkbox-label text-sm">{{ \App\Support\Permissions::label($permission) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-panel>
    @endif
</div>

@unless ($isAdmin)
@push('scripts')
<script>
    (function () {
        // Each group's "select all" drives its own checkboxes, and unticking one
        // member clears the group toggle — the ordinary two-way behaviour, wired
        // by data-group so no ids need coining per permission.
        document.querySelectorAll('[data-select-all]').forEach(function (master) {
            const group = master.getAttribute('data-select-all');
            const members = document.querySelectorAll('[data-group="' + group + '"]');

            const sync = function () {
                master.checked = members.length > 0 &&
                    Array.prototype.every.call(members, function (m) { return m.checked; });
            };

            master.addEventListener('change', function () {
                members.forEach(function (m) { m.checked = master.checked; });
            });
            members.forEach(function (m) { m.addEventListener('change', sync); });
            sync();
        });
    })();
</script>
@endpush
@endunless
