@extends('layouts.app')
@section('title', __('accounting.new_transfer'))
@section('page_title', __('accounting.new_transfer'))

@section('content')

{{--
    Moving money between two accounts.

    Both dropdowns arrive bare from `createTransfer()` — no "none" entry, unlike the
    journal form's lists — so each gets its own blank option here. On a required
    select that is not cosmetic: without it the form opens with the first account in
    the chart already chosen on both sides, which reads as a filled-in form and is
    refused by `different:transfer_from_id` only after the clerk presses save. A blank
    plus `required` makes the browser ask for a real choice instead.

    `location_id` is genuinely optional, and its blank means head office rather than
    "not answered" — the journal query treats a null location as visible to every
    branch-restricted user, which is why an unlocated transfer is a sensible thing to
    post rather than an oversight.

    Amount is a text input for the reason given in
    `accounting/accounts/_form.blade.php`: the service parses it with `numUf()`, which
    accepts Arabic-Indic digits and the tenant's separators, and `type="number"`
    submits an empty string for exactly those.
--}}

<form method="POST" action="{{ route('accounting.transfers.store') }}">
    @csrf

    <x-page-head :back="route('accounting.transfers.index')" :backLabel="__('accounting.transfers')"/>

    <div class="grid gap-6 lg:grid-cols-3">

        <x-panel :title="__('accounting.transfer_details')" icon="transfer"
                 :subtitle="__('accounting.transfer_details_hint')" class="lg:col-span-2">
            <div class="form-grid">
                <div class="field">
                    <label for="transfer_from_id" class="label label-required">
                        {{ __('accounting.from_account') }}
                    </label>
                    <select id="transfer_from_id" name="transfer_from_id"
                            @class(['select', 'input-invalid' => $errors->has('transfer_from_id')])
                            required>
                        <option value="">{{ __('lang_v1.select_account') }}</option>
                        @foreach ($accounts as $id => $label)
                            <option value="{{ $id }}"
                                    @selected((string) old('transfer_from_id') === (string) $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('transfer_from_id')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="transfer_to_id" class="label label-required">
                        {{ __('accounting.to_account') }}
                    </label>
                    <select id="transfer_to_id" name="transfer_to_id"
                            @class(['select', 'input-invalid' => $errors->has('transfer_to_id')])
                            required>
                        <option value="">{{ __('lang_v1.select_account') }}</option>
                        @foreach ($accounts as $id => $label)
                            <option value="{{ $id }}"
                                    @selected((string) old('transfer_to_id') === (string) $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="hint">{{ __('accounting.transfer_direction_hint') }}</p>
                    @error('transfer_to_id')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="amount" class="label label-required">{{ __('lang_v1.amount') }}</label>
                    <input type="text" inputmode="decimal" id="amount" name="amount"
                           @class(['input-numeric', 'input-invalid' => $errors->has('amount')])
                           value="{{ old('amount') }}" maxlength="32" required autofocus>
                    @error('amount')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="date" class="label label-required">{{ __('lang_v1.date') }}</label>
                    <input type="date" id="date" name="date"
                           @class(['input', 'input-invalid' => $errors->has('date')])
                           value="{{ old('date', now()->format('Y-m-d')) }}" required>
                    @error('date')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field sm:col-span-2">
                    <label for="location_id" class="label">{{ __('lang_v1.business_location') }}</label>
                    <select id="location_id" name="location_id"
                            @class(['select', 'input-invalid' => $errors->has('location_id')])>
                        <option value="">{{ __('lang_v1.none') }}</option>
                        @foreach ($locations as $id => $label)
                            <option value="{{ $id }}"
                                    @selected((string) old('location_id') === (string) $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('location_id')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field sm:col-span-2">
                    <label for="notes" class="label">{{ __('lang_v1.notes') }}</label>
                    <textarea id="notes" name="notes" rows="3"
                              @class(['textarea', 'input-invalid' => $errors->has('notes')])
                              maxlength="1000">{{ old('notes') }}</textarea>
                    @error('notes')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.how_this_works')" icon="info" class="self-start" quiet>
            <ul class="grid gap-3 text-sm text-slate-600">
                <li>{{ __('accounting.transfer_details_hint') }}</li>
                <li>{{ __('accounting.transfer_direction_hint') }}</li>
                <li>{{ __('accounting.no_edit_note') }}</li>
            </ul>
        </x-panel>
    </div>

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('accounting.transfers.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('accounting.new_transfer') }}
        </button>
    </div>
</form>
@endsection
