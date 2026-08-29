@extends('layouts.app')
@section('title', __('accounting.post_journal'))
@section('page_title', __('accounting.post_journal'))

@section('content')

{{--
    Posting a manual journal document.

    Eight blank line rows, rendered server-side and fixed. There is no "add row"
    button because there is no JavaScript on this screen, and eight is chosen rather
    than two because the alternative to a spare row is losing a whole entry: a clerk
    who needs a fifth line on a two-row form has to post two documents that each
    fail the balance check on their own. `normaliseLines()` drops a row with no
    account or no amount, so the spares cost nothing — "a row the clerk added and
    left blank is not an error, it is a row they did not use."

    The count grows to fit rejected input, so a refusal never silently truncates what
    was typed. It cannot grow past that, which is the honest limit of a no-JS form
    and the one place this screen is worse than an AJAX one.

    Amounts are text inputs, not `type="number"`. See the note in
    `accounting/accounts/_form.blade.php`: the service parses them with
    `FormattingService::numUf()`, which accepts Arabic-Indic digits and the tenant's
    own separators, and `type="number"` would submit an empty string for exactly
    those values.
--}}

@php
    /* Present only after a rejected submit — `storeJournal()` comes back with
       `withInput()`. Parsed with the same function the service uses, so the figures
       shown are the figures that were refused, not an approximation of them. */
    $submitted = is_array(old('lines')) ? old('lines') : [];

    $runDebit = 0.0;
    $runCredit = 0.0;

    foreach ($submitted as $line) {
        if ((int) ($line['chart_of_account_id'] ?? 0) <= 0) {
            continue;
        }

        $runDebit += formatting()->numUf($line['debit'] ?? 0);
        $runCredit += formatting()->numUf($line['credit'] ?? 0);
    }

    $rowCount = max(8, count($submitted));
    $wasSubmitted = $submitted !== [];
@endphp

<form method="POST" action="{{ route('accounting.journal.store') }}">
    @csrf

    <x-page-head :back="route('accounting.journal.index')" :backLabel="__('accounting.journal')"/>

    <div class="grid gap-6 lg:grid-cols-3">

        <div class="grid gap-6 lg:col-span-2">

            {{-- ============ The document ============ --}}
            <x-panel :title="__('accounting.journal_details')" icon="document"
                     :subtitle="__('accounting.journal_details_hint')">
                <div class="form-grid">
                    <div class="field">
                        <label for="date" class="label label-required">{{ __('lang_v1.date') }}</label>
                        <input type="date" id="date" name="date"
                               @class(['input', 'input-invalid' => $errors->has('date')])
                               value="{{ old('date', now()->format('Y-m-d')) }}" required>
                        @error('date')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="reference" class="label">{{ __('accounting.reference') }}</label>
                        <input id="reference" name="reference"
                               @class(['input', 'input-invalid' => $errors->has('reference')])
                               value="{{ old('reference') }}" maxlength="255">
                        @error('reference')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field sm:col-span-2">
                        <label for="name" class="label">{{ __('lang_v1.name') }}</label>
                        <input id="name" name="name"
                               @class(['input', 'input-invalid' => $errors->has('name')])
                               value="{{ old('name') }}" maxlength="255" autofocus>
                        @error('name')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field sm:col-span-2">
                        <label for="location_id" class="label">{{ __('lang_v1.business_location') }}</label>
                        <select id="location_id" name="location_id"
                                @class(['select', 'input-invalid' => $errors->has('location_id')])>
                            @foreach ($locations as $id => $label)
                                <option value="{{ $id }}"
                                        @selected((string) old('location_id') === (string) $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('location_id')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field sm:col-span-2">
                        <label for="notes" class="label">{{ __('lang_v1.notes') }}</label>
                        <textarea id="notes" name="notes" rows="2"
                                  @class(['textarea', 'input-invalid' => $errors->has('notes')])
                                  maxlength="1000">{{ old('notes') }}</textarea>
                        @error('notes')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </x-panel>

            {{-- ============ The lines ============ --}}
            <x-panel :title="__('accounting.journal_lines')" icon="list"
                     :subtitle="__('accounting.journal_lines_hint')" flush>
                <div class="table-wrap table-flush">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('lang_v1.account') }}</th>
                                <th class="th-numeric w-36">{{ __('accounting.line_debit') }}</th>
                                <th class="th-numeric w-36">{{ __('accounting.line_credit') }}</th>
                                <th class="w-48">{{ __('accounting.cost_center') }}</th>
                                <th class="w-56">{{ __('lang_v1.note') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @for ($i = 0; $i < $rowCount; $i++)
                                <tr>
                                    {{-- `accounts` arrives bare from `journalFormData()`,
                                         unlike the cost-centre and location lists, so the
                                         blank that makes a spare row submittable is added
                                         here. Without it every unused row would carry the
                                         first account in the chart. --}}
                                    <td>
                                        <select name="lines[{{ $i }}][chart_of_account_id]"
                                                @class(['select', 'input-invalid' => $errors->has('lines.'.$i.'.chart_of_account_id')])
                                                aria-label="{{ __('lang_v1.account') }}">
                                            <option value="">{{ __('lang_v1.none') }}</option>
                                            @foreach ($accounts as $id => $label)
                                                <option value="{{ $id }}"
                                                        @selected((string) old('lines.'.$i.'.chart_of_account_id') === (string) $id)>
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('lines.'.$i.'.chart_of_account_id')
                                            <p class="field-error">{{ $message }}</p>
                                        @enderror
                                    </td>

                                    <td>
                                        <input type="text" inputmode="decimal" name="lines[{{ $i }}][debit]"
                                               @class(['input-numeric', 'input-invalid' => $errors->has('lines.'.$i.'.debit')])
                                               value="{{ old('lines.'.$i.'.debit') }}"
                                               maxlength="32" placeholder="0"
                                               aria-label="{{ __('accounting.line_debit') }}">
                                    </td>

                                    <td>
                                        <input type="text" inputmode="decimal" name="lines[{{ $i }}][credit]"
                                               @class(['input-numeric', 'input-invalid' => $errors->has('lines.'.$i.'.credit')])
                                               value="{{ old('lines.'.$i.'.credit') }}"
                                               maxlength="32" placeholder="0"
                                               aria-label="{{ __('accounting.line_credit') }}">
                                    </td>

                                    <td>
                                        <select name="lines[{{ $i }}][cost_center_id]"
                                                @class(['select', 'input-invalid' => $errors->has('lines.'.$i.'.cost_center_id')])
                                                aria-label="{{ __('accounting.cost_center') }}">
                                            @foreach ($costCenters as $id => $label)
                                                <option value="{{ $id }}"
                                                        @selected((string) old('lines.'.$i.'.cost_center_id') === (string) $id)>
                                                    {{ $label }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>

                                    <td>
                                        <input name="lines[{{ $i }}][notes]"
                                               @class(['input', 'input-invalid' => $errors->has('lines.'.$i.'.notes')])
                                               value="{{ old('lines.'.$i.'.notes') }}"
                                               maxlength="255"
                                               aria-label="{{ __('lang_v1.note') }}">
                                    </td>
                                </tr>
                            @endfor
                        </tbody>

                        {{-- Only after a refusal. On a blank form both totals are zero
                             and a row of zeros says nothing; on a refused one it is the
                             difference the clerk has to find. --}}
                        @if ($wasSubmitted)
                            <tfoot>
                                <tr>
                                    <th class="text-end">{{ __('lang_v1.total') }}</th>
                                    <td @class(['cell-numeric', 'text-rose-700' => abs($runDebit - $runCredit) > 0.0001])>
                                        {{ __('accounting.running_debit') }}
                                        <span class="block font-semibold">@format_currency($runDebit)</span>
                                    </td>
                                    <td @class(['cell-numeric', 'text-rose-700' => abs($runDebit - $runCredit) > 0.0001])>
                                        {{ __('accounting.running_credit') }}
                                        <span class="block font-semibold">@format_currency($runCredit)</span>
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>

                @error('lines')<p class="field-error px-5 pb-4">{{ $message }}</p>@enderror
            </x-panel>
        </div>

        {{-- ============ What the form will and will not do ============ --}}
        <x-panel :title="__('lang_v1.how_this_works')" icon="info" class="self-start" quiet>
            <ul class="grid gap-3 text-sm text-slate-600">
                <li>{{ __('accounting.journal_lines_hint') }}</li>
                <li>{{ __('accounting.balance_check_note') }}</li>
                <li>{{ __('accounting.no_edit_note') }}</li>
            </ul>
        </x-panel>
    </div>

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('accounting.journal.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('accounting.post_journal') }}
        </button>
    </div>
</form>
@endsection
