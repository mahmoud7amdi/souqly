{{--
    The cost-centre form, shared by create and edit.

    Split the same way the account form is split, for the same reason: the left panel
    is what the centre *is* — its code, its place in the tree, and who answers for it
    — and the right panel is the budget, which is a figure reports compare against
    and not a property of the centre at all. Nothing enforces a budget, so mixing it
    into the identity fields would overstate what saving it does.

    Neither `type` nor `budget_period` has a blank option, and both are `required`.
    Unlike the account form's type — where no default is defensible — a cost centre
    is a cost centre unless told otherwise, and a budget is monthly unless told
    otherwise. So each opens on the honest default rather than on a blank the rules
    would always refuse.
--}}
@php
    $record = $record ?? null;

    /* Checked by default on a new centre: you do not create a cost centre in order
       to leave it switched off. On edit the row's own value wins.

       The name is `is_active`, not `active` as on the account form — the column
       differs between the two tables, and `storeCostCenter()` reads this one with
       `$request->boolean('is_active')` outside the validation array. */
    $isActive = (bool) old('is_active', $record->is_active ?? true);

    /* Blank rather than a pre-filled zero. `budget_amount` is nullable and a zero
       budget means the same thing as none, so showing "0" in the box would state a
       decision the tenant never made. */
    $budget = old('budget_amount', $record && (float) $record->budget_amount > 0
        ? $record->budget_amount
        : '');
@endphp

<div class="grid gap-6 lg:grid-cols-3">

    {{-- ============ What the centre is ============ --}}
    <x-panel :title="__('accounting.cost_center_details')" icon="layers"
             :subtitle="__('accounting.cost_center_details_hint')" class="lg:col-span-2">
        <div class="form-grid">
            {{-- Required and unique per tenant, unlike a GL code — the schema
                 declares `unique(['business_id', 'code'])` and every report
                 addresses the centre by it. `force-ltr` because codes read as
                 identifiers even on an Arabic screen, the same treatment the
                 listing gives the column. --}}
            <div class="field">
                <label for="code" class="label label-required">{{ __('accounting.cost_center_code') }}</label>
                <input id="code" name="code"
                       @class(['input', 'force-ltr', 'input-invalid' => $errors->has('code')])
                       value="{{ old('code', $record->code ?? '') }}"
                       maxlength="255" required autofocus>
                <p class="hint">{{ __('accounting.cost_center_code_hint') }}</p>
                @error('code')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="type" class="label label-required">{{ __('accounting.cost_center_type') }}</label>
                <select id="type" name="type"
                        @class(['select', 'input-invalid' => $errors->has('type')]) required>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}"
                                @selected((string) old('type', $record->type ?? 'cost') === (string) $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('type')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field sm:col-span-2">
                <label for="name" class="label label-required">{{ __('lang_v1.name') }}</label>
                <input id="name" name="name"
                       @class(['input', 'input-invalid' => $errors->has('name')])
                       value="{{ old('name', $record->name ?? '') }}"
                       maxlength="255" required>
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            {{-- `costCenterFormData($id)` drops the record being edited from this
                 list, so a centre cannot be made its own parent. It does not drop
                 the centre's own descendants, so a deeper cycle is still reachable
                 here and is refused by the service rather than by the form. --}}
            <div class="field">
                <label for="parent_id" class="label">{{ __('accounting.parent_cost_center') }}</label>
                <select id="parent_id" name="parent_id"
                        @class(['select', 'input-invalid' => $errors->has('parent_id')])>
                    @foreach ($parents as $id => $label)
                        <option value="{{ $id }}"
                                @selected((string) old('parent_id', $record->parent_id ?? '') === (string) $id)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('parent_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="manager_id" class="label">{{ __('accounting.manager') }}</label>
                <select id="manager_id" name="manager_id"
                        @class(['select', 'input-invalid' => $errors->has('manager_id')])>
                    @foreach ($managers as $id => $label)
                        <option value="{{ $id }}"
                                @selected((string) old('manager_id', $record->manager_id ?? '') === (string) $id)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('manager_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="location_id" class="label">{{ __('lang_v1.business_location') }}</label>
                <select id="location_id" name="location_id"
                        @class(['select', 'input-invalid' => $errors->has('location_id')])>
                    @foreach ($locations as $id => $label)
                        <option value="{{ $id }}"
                                @selected((string) old('location_id', $record->location_id ?? '') === (string) $id)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('location_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            {{-- A true `integer` rule, so `type="number"` is the honest rendering
                 here: the browser refusing a decimal is the same answer the server
                 would give. That is not true of the budget field opposite, which is
                 validated as a string and parsed with `numUf()`. --}}
            <div class="field">
                <label for="sort_order" class="label">{{ __('accounting.sort_order') }}</label>
                <input type="number" step="1" min="0" max="9999" id="sort_order" name="sort_order"
                       @class(['input', 'input-numeric', 'force-ltr', 'input-invalid' => $errors->has('sort_order')])
                       value="{{ old('sort_order', $record->sort_order ?? '') }}">
                <p class="hint">{{ __('accounting.sort_order_hint') }}</p>
                @error('sort_order')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field sm:col-span-2">
                <label for="description" class="label">{{ __('lang_v1.description') }}</label>
                <textarea id="description" name="description" rows="3"
                          @class(['textarea', 'input-invalid' => $errors->has('description')])
                          maxlength="1000">{{ old('description', $record->description ?? '') }}</textarea>
                @error('description')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- Set apart from the grid because it is not a property of the centre but a
             decision about whether it may still be used. --}}
        <div class="surface-quiet mt-6">
            <div class="checkbox-row">
                <input type="checkbox" id="is_active" name="is_active" value="1"
                       class="checkbox" @checked($isActive)>
                <div>
                    <label for="is_active" class="checkbox-label">{{ __('lang_v1.active') }}</label>
                    <p class="checkbox-hint">{{ __('accounting.cost_center_active_hint') }}</p>
                </div>
            </div>
        </div>
    </x-panel>

    {{-- ============ The budget ============ --}}
    <x-panel :title="__('accounting.budget_details')" icon="coins"
             :subtitle="__('accounting.budget_details_hint')" class="self-start">
        <div class="grid gap-5">
            {{-- Text, not `type="number"`, for the reason set out at length in
                 `accounting/accounts/_form.blade.php`: `costCenterRules()` validates
                 this as `nullable|string|max:32` and the service parses it with
                 `FormattingService::numUf()`, which accepts Arabic-Indic digits and
                 the tenant's own separators. `type="number"` would submit an empty
                 string for exactly those and drop the figure without saying so. --}}
            <div class="field">
                <label for="budget_amount" class="label">{{ __('accounting.budget_amount') }}</label>
                <input type="text" inputmode="decimal" id="budget_amount" name="budget_amount"
                       @class(['input-numeric', 'input-invalid' => $errors->has('budget_amount')])
                       value="{{ $budget }}" maxlength="32" placeholder="0">
                @error('budget_amount')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            {{-- Required even when the amount is blank. The rules say so, and the
                 period is what a budget of zero would still be measured over if one
                 were entered later, so there is no state in which it is meaningless. --}}
            <div class="field">
                <label for="budget_period" class="label label-required">
                    {{ __('accounting.budget_period') }}
                </label>
                <select id="budget_period" name="budget_period"
                        @class(['select', 'input-invalid' => $errors->has('budget_period')]) required>
                    @foreach ($periods as $value => $label)
                        <option value="{{ $value }}"
                                @selected((string) old('budget_period', $record->budget_period ?? 'monthly') === (string) $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('budget_period')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        <x-slot:footer>
            <p class="text-sm text-slate-600">{{ __('accounting.budget_details_hint') }}</p>
        </x-slot:footer>
    </x-panel>
</div>
