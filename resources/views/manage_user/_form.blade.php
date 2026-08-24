{{--
    Staff account form, shared by create and edit.

    Written by hand rather than through crud/_form.blade.php for one specific
    reason: that partial renders `value="{{ $value }}"` on any input it does not
    recognise, and a `password` field would put the stored bcrypt hash straight
    into the HTML. The password inputs here are deliberately always empty.

    Expects: $user (null on create), $roles, $locations, $languages,
    $assignedRole, $assignedLocations, $allLocations.
--}}
@php
    $user = $user ?? null;
    $isNew = empty($user);
    $assignedLocations = old('location_ids', $assignedLocations ?? []);
    $allLocations = old('access_all_locations', $allLocations ?? false);
@endphp

<div class="grid gap-6">
    <x-panel :title="__('lang_v1.personal_details')" icon="user">
        <div class="form-grid">
            <div class="field">
                <label for="surname" class="label">{{ __('lang_v1.prefix') }}</label>
                <input id="surname" name="surname" @class(['input', 'input-invalid' => $errors->has('surname')])
                       value="{{ old('surname', $user->surname ?? '') }}"
                       placeholder="{{ __('lang_v1.prefix_placeholder') }}">
                @error('surname')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="first_name" class="label label-required">{{ __('lang_v1.first_name') }}</label>
                <input id="first_name" name="first_name" required
                       @class(['input', 'input-invalid' => $errors->has('first_name')])
                       value="{{ old('first_name', $user->first_name ?? '') }}">
                @error('first_name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="last_name" class="label">{{ __('lang_v1.last_name') }}</label>
                <input id="last_name" name="last_name" @class(['input', 'input-invalid' => $errors->has('last_name')])
                       value="{{ old('last_name', $user->last_name ?? '') }}">
                @error('last_name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="email" class="label">{{ __('lang_v1.email') }}</label>
                <input type="email" id="email" name="email" class="input force-ltr"
                       value="{{ old('email', $user->email ?? '') }}">
                @error('email')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="contact_no" class="label">{{ __('lang_v1.mobile') }}</label>
                <input id="contact_no" name="contact_no" class="input force-ltr"
                       value="{{ old('contact_no', $user->contact_no ?? '') }}">
                @error('contact_no')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field sm:col-span-2">
                <label for="address" class="label">{{ __('lang_v1.address') }}</label>
                <textarea id="address" name="address" rows="2" class="textarea"
                >{{ old('address', $user->address ?? '') }}</textarea>
                @error('address')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </x-panel>

    <x-panel :title="__('lang_v1.login_details')" icon="key">
        <div class="form-grid">
            @if ($isNew)
                <div class="field">
                    <label for="username" class="label label-required">{{ __('lang_v1.username') }}</label>
                    <input id="username" name="username" required autocomplete="off"
                           @class(['input', 'force-ltr', 'input-invalid' => $errors->has('username')])
                           value="{{ old('username') }}">
                    <p class="hint">{{ __('lang_v1.username_hint') }}</p>
                    @error('username')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            @else
                {{-- Shown, never editable: it is what this person types to log in,
                     and changing it out from under them is a support action. --}}
                <div class="field">
                    <label class="label">{{ __('lang_v1.username') }}</label>
                    <p class="input-static force-ltr">{{ $user->username }}</p>
                </div>
            @endif

            <div class="field">
                <label for="language" class="label label-required">{{ __('lang_v1.language') }}</label>
                <select id="language" name="language" class="select" required>
                    @foreach ($languages as $code => $name)
                        <option value="{{ $code }}"
                            @selected(old('language', $user->language ?? config('app.locale')) === $code)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                @error('language')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="password" @class(['label', 'label-required' => $isNew])>
                    {{ __('lang_v1.password') }}
                </label>
                {{-- Always rendered empty. The field is a way to set a new
                     password, never a way to read the current one. --}}
                <input type="password" id="password" name="password" autocomplete="new-password"
                       @class(['input', 'force-ltr', 'input-invalid' => $errors->has('password')])
                       @if ($isNew) required @endif>
                @unless ($isNew)
                    <p class="hint">{{ __('lang_v1.password_leave_blank_hint') }}</p>
                @endunless
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="password_confirmation" @class(['label', 'label-required' => $isNew])>
                    {{ __('lang_v1.confirm_password') }}
                </label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       autocomplete="new-password" class="input force-ltr"
                       @if ($isNew) required @endif>
            </div>

            <div class="field">
                <label for="status" class="label label-required">{{ __('lang_v1.status') }}</label>
                <select id="status" name="status" class="select" required>
                    @foreach (['active', 'inactive', 'terminated'] as $status)
                        <option value="{{ $status }}"
                            @selected(old('status', $user->status ?? 'active') === $status)>
                            {{ __('lang_v1.'.$status) }}
                        </option>
                    @endforeach
                </select>
                @error('status')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label class="checkbox-row">
                    <input type="checkbox" name="allow_login" value="1" class="checkbox"
                           @checked(old('allow_login', $user->allow_login ?? true))>
                    <span class="checkbox-label">
                        {{ __('lang_v1.allow_login') }}
                        <span class="checkbox-hint">{{ __('lang_v1.allow_login_hint') }}</span>
                    </span>
                </label>
            </div>
        </div>
    </x-panel>

    <x-panel :title="__('lang_v1.role_and_access')" icon="shield">
        <div class="form-grid">
            <div class="field sm:col-span-2">
                <label for="role_id" class="label label-required">{{ __('lang_v1.role') }}</label>
                <select id="role_id" name="role_id"
                        @class(['select', 'input-invalid' => $errors->has('role_id')]) required>
                    @foreach ($roles as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('role_id', $assignedRole) === (int) $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                <p class="hint">{{ __('lang_v1.role_hint') }}</p>
                @error('role_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="surface-quiet mt-4">
            <div class="section-head">
                <h4 class="font-semibold">{{ __('lang_v1.location_access') }}</h4>
            </div>

            <label class="checkbox-row mt-3">
                <input type="checkbox" name="access_all_locations" value="1" class="checkbox"
                       data-all-locations @checked($allLocations)>
                <span class="checkbox-label">
                    {{ __('lang_v1.all_locations') }}
                    <span class="checkbox-hint">{{ __('lang_v1.all_locations_hint') }}</span>
                </span>
            </label>

            {{-- Hidden rather than removed while "all locations" is ticked: the
                 individual ticks stay on screen the moment it is unticked again,
                 so nobody has to rebuild the list after a misclick. --}}
            <div @class(['mt-3', 'grid', 'gap-x-4', 'gap-y-2', 'sm:grid-cols-2', 'lg:grid-cols-3',
                         'hidden' => $allLocations]) data-location-list>
                @forelse ($locations as $id => $name)
                    <label class="checkbox-row">
                        <input type="checkbox" name="location_ids[]" value="{{ $id }}" class="checkbox"
                               @checked(in_array((int) $id, array_map('intval', (array) $assignedLocations), true))>
                        <span class="checkbox-label text-sm">{{ $name }}</span>
                    </label>
                @empty
                    <p class="hint sm:col-span-2 lg:col-span-3">{{ __('lang_v1.no_locations_yet') }}</p>
                @endforelse
            </div>
            @error('location_ids')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </x-panel>

    <x-panel :title="__('lang_v1.sales_settings')" icon="cart">
        <div class="form-grid">
            <div class="field sm:col-span-2">
                <label class="checkbox-row">
                    <input type="checkbox" name="is_cmmsn_agnt" value="1" class="checkbox"
                           data-commission-toggle @checked(old('is_cmmsn_agnt', $user->is_cmmsn_agnt ?? false))>
                    <span class="checkbox-label">
                        {{ __('lang_v1.is_commission_agent') }}
                        <span class="checkbox-hint">{{ __('lang_v1.is_commission_agent_hint') }}</span>
                    </span>
                </label>
            </div>

            <div @class(['field', 'hidden' => ! old('is_cmmsn_agnt', $user->is_cmmsn_agnt ?? false)])
                 data-commission-field>
                <label for="cmmsn_percent" class="label">{{ __('lang_v1.commission_percent') }}</label>
                <input type="text" inputmode="decimal" id="cmmsn_percent" name="cmmsn_percent"
                       @class(['input-numeric', 'input-invalid' => $errors->has('cmmsn_percent')])
                       value="{{ old('cmmsn_percent', $user->cmmsn_percent ?? 0) }}">
                @error('cmmsn_percent')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="max_sales_discount_percent" class="label">
                    {{ __('lang_v1.max_sales_discount_percent') }}
                </label>
                <input type="text" inputmode="decimal" id="max_sales_discount_percent"
                       name="max_sales_discount_percent"
                       @class(['input-numeric', 'input-invalid' => $errors->has('max_sales_discount_percent')])
                       value="{{ old('max_sales_discount_percent', $user->max_sales_discount_percent ?? '') }}">
                <p class="hint">{{ __('lang_v1.max_sales_discount_hint') }}</p>
                @error('max_sales_discount_percent')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </x-panel>
</div>

@push('scripts')
<script>
    (function () {
        // Two dependent blocks: the location list is irrelevant once "all
        // locations" is granted, and the commission rate is irrelevant unless the
        // person is a commission agent. Both are hidden rather than disabled so
        // their values survive a mistaken tick.
        const allLocations = document.querySelector('[data-all-locations]');
        const locationList = document.querySelector('[data-location-list]');

        if (allLocations && locationList) {
            allLocations.addEventListener('change', function () {
                locationList.classList.toggle('hidden', allLocations.checked);
            });
        }

        const commission = document.querySelector('[data-commission-toggle]');
        const commissionField = document.querySelector('[data-commission-field]');

        if (commission && commissionField) {
            commission.addEventListener('change', function () {
                commissionField.classList.toggle('hidden', ! commission.checked);
            });
        }
    })();
</script>
@endpush
