{{--
    The chart-of-accounts form, shared by create and edit.

    Two panels, because an account answers two separate questions and mixing them
    is how the second gets filled in by accident. The left panel is what the account
    *is* — its name, its place in the tree, and which side it is naturally on. The
    right panel is the opening position, which most tenants will leave alone
    forever: it only matters on the day a chart is carried in from another system,
    and after that first save editing it silently restates every balance the
    reports have ever shown.

    The type select has no blank option. An account without a type has no natural
    side, so there is no sensible default the form could offer and no state in which
    an untyped account is useful — `accountRules()` requires it, and offering a
    blank that is always refused just moves the refusal later.
--}}
@php
    $record = $record ?? null;

    /* Checked by default on a new account: you do not create an account in order to
       leave it archived. On edit the row's own value wins. */
    $isActive = (bool) old('active', $record->active ?? true);
@endphp

<div class="grid gap-6 lg:grid-cols-3">

    {{-- ============ What the account is ============ --}}
    <x-panel :title="__('accounting.account_details')" icon="book"
             :subtitle="__('accounting.account_details_hint')" class="lg:col-span-2">
        <div class="form-grid">
            <div class="field sm:col-span-2">
                <label for="name" class="label label-required">{{ __('lang_v1.name') }}</label>
                <input id="name" name="name"
                       @class(['input', 'input-invalid' => $errors->has('name')])
                       value="{{ old('name', $record->name ?? '') }}"
                       maxlength="255" required autofocus>
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            {{-- An integer, not a string: `accountRules()` validates it as one, and
                 the reports sort on it numerically. A code of "1100-A" would sort
                 between 1100 and 1101 as text and nowhere sensible as a number. --}}
            <div class="field">
                <label for="gl_code" class="label">{{ __('accounting.gl_code') }}</label>
                <input type="number" step="1" min="0" id="gl_code" name="gl_code"
                       @class(['input', 'input-numeric', 'force-ltr', 'input-invalid' => $errors->has('gl_code')])
                       value="{{ old('gl_code', $record->gl_code ?? '') }}">
                <p class="hint">{{ __('accounting.gl_code_hint') }}</p>
                @error('gl_code')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="account_type" class="label label-required">{{ __('accounting.account_type') }}</label>
                <select id="account_type" name="account_type"
                        @class(['select', 'input-invalid' => $errors->has('account_type')]) required>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}"
                                @selected((string) old('account_type', $record->account_type ?? 'asset') === (string) $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                <p class="hint">{{ __('accounting.account_type_hint') }}</p>
                @error('account_type')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field sm:col-span-2">
                <label for="parent_id" class="label">{{ __('accounting.parent_account') }}</label>
                <select id="parent_id" name="parent_id"
                        @class(['select', 'input-invalid' => $errors->has('parent_id')])>
                    @foreach ($parents as $id => $label)
                        <option value="{{ $id }}"
                                @selected((string) old('parent_id', $record->parent_id ?? '') === (string) $id)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                <p class="hint">{{ __('accounting.parent_account_hint') }}</p>
                @error('parent_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field sm:col-span-2">
                <label for="notes" class="label">{{ __('lang_v1.notes') }}</label>
                <textarea id="notes" name="notes" rows="3"
                          @class(['textarea', 'input-invalid' => $errors->has('notes')])
                          maxlength="1000">{{ old('notes', $record->notes ?? '') }}</textarea>
                @error('notes')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- Set apart from the grid because neither is a property of the account —
             both are decisions about how it may be used. --}}
        <div class="surface-quiet mt-6">
            <div class="checkbox-row">
                <input type="checkbox" id="active" name="active" value="1"
                       class="checkbox" @checked($isActive)>
                <div>
                    <label for="active" class="checkbox-label">{{ __('lang_v1.active') }}</label>
                    <p class="checkbox-hint">{{ __('accounting.account_active_hint') }}</p>
                </div>
            </div>

            <div class="checkbox-row mt-4">
                <input type="checkbox" id="allow_manual" name="allow_manual" value="1"
                       class="checkbox" @checked((bool) old('allow_manual', $record->allow_manual ?? false))>
                <div>
                    <label for="allow_manual" class="checkbox-label">{{ __('accounting.allow_manual') }}</label>
                    <p class="checkbox-hint">{{ __('accounting.allow_manual_hint') }}</p>
                </div>
            </div>
        </div>
    </x-panel>

    {{-- ============ The opening position ============ --}}
    <x-panel :title="__('accounting.account_opening')" icon="scale"
             :subtitle="__('accounting.account_opening_hint')" class="self-start">
        <div class="grid gap-5">
            {{-- Text, not `type="number"`.

                 `accountRules()` validates this as a string and the service puts it
                 through `FormattingService::numUf()`, which exists to accept what a
                 real user types: Arabic-Indic digits, the tenant's own thousand and
                 decimal separators, and the bidi marks an RTL input smuggles in.
                 `type="number"` enforces the browser's own numeric grammar instead,
                 and a value it cannot parse — ١٢٣٤٫٥ on an Arabic keyboard, or
                 1,234.5 anywhere — submits as an empty string with nothing on screen
                 to say the figure was dropped. `inputmode="decimal"` keeps the mobile
                 numeric keypad without that. Same reason on the journal and transfer
                 forms; `gl_code` above is the exception, because there the rule really
                 is `integer` and the browser refusing what the server would refuse is
                 the honest rendering. --}}
            <div class="field">
                <label for="opening_balance" class="label">{{ __('lang_v1.opening_balance') }}</label>
                <input type="text" inputmode="decimal" id="opening_balance" name="opening_balance"
                       @class(['input-numeric', 'input-invalid' => $errors->has('opening_balance')])
                       value="{{ old('opening_balance', $record->opening_balance ?? 0) }}"
                       maxlength="32">
                <p class="hint">{{ __('accounting.opening_balance_hint') }}</p>
                @error('opening_balance')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- Stated on the form rather than only in the trial balance's footnote,
             because this is where the imbalance would be created. --}}
        <x-slot:footer>
            <p class="text-sm text-slate-600">{{ __('accounting.opening_not_balanced') }}</p>
        </x-slot:footer>
    </x-panel>
</div>
