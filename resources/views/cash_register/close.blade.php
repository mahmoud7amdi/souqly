@extends('layouts.app')
@section('title', __('lang_v1.close_register'))
@section('page_title', __('lang_v1.cash_register'))

@section('content')

@php
    /* Keys can be fractional ("0.5"), so the previous input is read by direct
       array access rather than old('denominations.0.5'), which dot-notation would
       read as denominations[0][5]. */
    $oldCounts = (array) old('denominations', []);
    $hasGrid = ! empty($denominations);
@endphp

<x-page-head :title="__('lang_v1.close_register')"
             :back="route('cash-register.show', $register->id)"
             :backLabel="__('lang_v1.register_session').' #'.$register->id">
    <x-slot:subtitle>
        {{ or_dash($register->user->user_full_name ?? null) }}
        <span class="text-slate-300">·</span>
        {{ $register->location->name ?? __('lang_v1.all_locations') }}
        <span class="text-slate-300">·</span>
        {{ __('lang_v1.opened_at') }} @format_datetime($register->created_at)
    </x-slot:subtitle>
</x-page-head>

{{--
    Counting the drawer.

    The screen is built around one comparison: what the till says should be in the
    drawer, against what the cashier actually finds in it. Everything else on the
    page is subordinate to that, which is why the expected figure sits in the rail
    and stays visible while the counting happens, and why the difference is shown
    the moment there is something to compare — not after saving.

    A counted breakdown beats a typed total ({@see \App\Services\CashRegisterService::close()}),
    so the two are never offered together: with denominations configured the form
    asks only for counts, and without them it asks only for a total. Offering both
    would invite them to disagree and then silently ignore one.
--}}
<form method="POST" action="{{ route('cash-register.close', $register->id) }}" data-close-form>
    @csrf

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-panel :title="__('lang_v1.count_the_drawer')" icon="calculator"
                     :subtitle="$hasGrid ? __('lang_v1.enter_how_many_of_each') : __('lang_v1.enter_the_cash_you_counted')">
                @if ($hasGrid)
                    <div class="space-y-2" data-denominations>
                        @foreach ($denominations as $value)
                            <div class="flex items-center gap-3 rounded-xl bg-slate-50 px-4 py-2.5">
                                <label for="denom_{{ $loop->index }}"
                                       class="w-20 shrink-0 font-mono text-sm font-bold tabular-nums text-slate-700">
                                    {{ $value }}
                                </label>

                                <span class="text-slate-400">×</span>

                                <input id="denom_{{ $loop->index }}"
                                       name="denominations[{{ $value }}]"
                                       type="number" min="0" step="1" inputmode="numeric"
                                       value="{{ $oldCounts[$value] ?? '' }}"
                                       class="input input-numeric w-24 shrink-0"
                                       placeholder="0"
                                       data-denom="{{ $value }}">

                                <span class="ms-auto font-mono text-sm tabular-nums text-slate-500"
                                      data-line-total>—</span>
                            </div>
                        @endforeach
                    </div>

                    @error('denominations.*')<p class="field-error mt-3">{{ $message }}</p>@enderror

                    <div class="dl mt-5">
                        <div class="dl-total">
                            <dt class="dl-key">{{ __('lang_v1.counted_total') }}</dt>
                            <dd class="dl-total-value" data-counted-total>
                                @format_currency(0)
                            </dd>
                        </div>
                    </div>

                    <noscript>
                        <p class="hint mt-3">{{ __('lang_v1.total_is_calculated_on_save') }}</p>
                    </noscript>
                @else
                    {{-- No denominations configured for this business, so the form
                         asks for the figure rather than inventing note values it
                         cannot know ({@see CashRegisterController::suggestedDenominations()}). --}}
                    <div class="max-w-xs">
                        <div class="field">
                            <label for="closing_amount" class="label">{{ __('lang_v1.counted_total') }}</label>
                            <input id="closing_amount" name="closing_amount" type="text" inputmode="decimal"
                                   value="{{ old('closing_amount') }}"
                                   class="input input-numeric input-lg @error('closing_amount') input-invalid @enderror"
                                   placeholder="0.00" data-closing-amount autofocus>
                            @error('closing_amount')
                                <p class="field-error">{{ $message }}</p>
                            @else
                                <p class="hint">{{ __('lang_v1.set_denominations_in_pos_settings') }}</p>
                            @enderror
                        </div>
                    </div>
                @endif

                {{-- Slips and cheques are counted, not totalled: they are pieces of
                     paper to hand over at the end of the shift, and the money they
                     represent is already in the method breakdown. --}}
                <div class="surface-quiet mt-6">
                    <p class="section-label">{{ __('lang_v1.also_in_the_drawer') }}</p>

                    <div class="form-grid">
                        <div class="field">
                            <label for="total_card_slips" class="label">{{ __('lang_v1.card_slips') }}</label>
                            <input id="total_card_slips" name="total_card_slips"
                                   type="number" min="0" step="1" inputmode="numeric"
                                   value="{{ old('total_card_slips') }}"
                                   class="input input-numeric @error('total_card_slips') input-invalid @enderror"
                                   placeholder="0">
                            @error('total_card_slips')
                                <p class="field-error">{{ $message }}</p>
                            @else
                                <p class="hint">{{ __('lang_v1.how_many_slips') }}</p>
                            @enderror
                        </div>

                        <div class="field">
                            <label for="total_cheques" class="label">{{ __('lang_v1.cheques') }}</label>
                            <input id="total_cheques" name="total_cheques"
                                   type="number" min="0" step="1" inputmode="numeric"
                                   value="{{ old('total_cheques') }}"
                                   class="input input-numeric @error('total_cheques') input-invalid @enderror"
                                   placeholder="0">
                            @error('total_cheques')
                                <p class="field-error">{{ $message }}</p>
                            @else
                                <p class="hint">{{ __('lang_v1.how_many_cheques') }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="field mt-6">
                    <label for="closing_note" class="label">{{ __('lang_v1.closing_note') }}</label>
                    <textarea id="closing_note" name="closing_note" rows="2"
                              class="textarea @error('closing_note') input-invalid @enderror"
                              placeholder="{{ __('lang_v1.explain_any_difference') }}">{{ old('closing_note') }}</textarea>
                    @error('closing_note')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </x-panel>
        </div>

        <x-panel :title="__('lang_v1.what_the_till_says')" icon="scale" class="self-start">
            <p class="stat-value text-3xl">@format_currency($summary['cash_in_hand'])</p>
            <p class="stat-hint mt-1">{{ __('lang_v1.expected_cash_in_hand') }}</p>

            <dl class="dl mt-5">
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.opening_float') }}</dt>
                    <dd class="dl-value">@format_currency($summary['opening'])</dd>
                </div>

                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.cash_taken') }}</dt>
                    <dd class="dl-value">@format_currency($summary['by_method']['cash'] ?? 0)</dd>
                </div>

                @if ($summary['refunds'] > 0.0001)
                    <div class="dl-row">
                        <dt class="dl-key">{{ __('lang_v1.refunds') }}</dt>
                        <dd class="dl-value text-amber-700">@format_currency($summary['refunds'])</dd>
                    </div>
                @endif

                {{-- Filled in as the counting happens. Blank until then, because a
                     difference of zero before anything has been counted is not a
                     balanced drawer — it is an unasked question. --}}
                <div class="dl-total">
                    <dt class="dl-key" data-variance-label>{{ __('lang_v1.difference') }}</dt>
                    <dd class="dl-total-value" data-variance>—</dd>
                </div>
            </dl>

            <p class="hint mt-5">{{ __('lang_v1.closing_cannot_be_undone') }}</p>
        </x-panel>
    </div>

    {{-- Accent first in document order so Enter commits the count, reversed
         visually so the commit stays at the inline end. --}}
    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.this_ends_the_shift') }}</span>

        <button type="submit" class="btn-accent order-3">
            <x-nav-icon name="lock" :size="4"/>
            {{ __('lang_v1.close_register') }}
        </button>

        <button type="submit" name="close_and_pos" value="1" class="btn-secondary order-2">
            {{ __('lang_v1.close_and_open_a_new_one') }}
        </button>

        <a href="{{ route('cash-register.show', $register->id) }}" class="btn-secondary order-1">
            {{ __('lang_v1.cancel') }}
        </a>
    </div>
</form>

@push('scripts')
<script>
    /*
     * Arithmetic only. The server recomputes every figure from the posted counts
     * ({@see CashRegisterService::close()}), so nothing here is trusted — it exists
     * so the cashier sees the difference while there is still time to recount,
     * rather than after the shift is already closed.
     */
    (function () {
        const form = document.querySelector('[data-close-form]');
        if (! form) return;

        const expected = {{ (float) $summary['cash_in_hand'] }};
        const money = new Intl.NumberFormat(document.documentElement.lang || 'en', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

        const counts = form.querySelectorAll('[data-denom]');
        const closingInput = form.querySelector('[data-closing-amount]');
        const countedCell = form.querySelector('[data-counted-total]');
        const varianceCell = form.querySelector('[data-variance]');
        const varianceLabel = form.querySelector('[data-variance-label]');

        const LABELS = {
            difference: @json(__('lang_v1.difference')),
            short: @json(__('lang_v1.short_by')),
            over: @json(__('lang_v1.over_by')),
        };

        /**
         * What has been counted so far, or null when nothing has been.
         *
         * The distinction matters: a typed 0 is a real answer — an empty drawer —
         * and deserves a variance. An untouched form is not an answer at all, and
         * showing "short by the whole float" before the cashier has counted a
         * single note would be alarming and wrong.
         */
        function counted() {
            if (closingInput) {
                const raw = closingInput.value.trim();

                return raw === '' ? null : (parseFloat(raw.replace(/,/g, '')) || 0);
            }

            let total = 0;
            let touched = false;

            counts.forEach(function (input) {
                const value = parseFloat(input.dataset.denom) || 0;
                const count = parseInt(input.value, 10);
                const line = input.closest('div').querySelector('[data-line-total]');

                if (! isNaN(count) && count > 0) {
                    touched = true;
                    total += value * count;
                    if (line) line.textContent = money.format(value * count);
                } else {
                    if (! isNaN(count)) touched = true;
                    if (line) line.textContent = '—';
                }
            });

            return touched ? total : null;
        }

        function render() {
            const total = counted();

            if (countedCell) {
                countedCell.textContent = money.format(total || 0);
            }

            if (total === null) {
                varianceCell.textContent = '—';
                varianceCell.className = 'dl-total-value';
                varianceLabel.textContent = LABELS.difference;
                return;
            }

            const difference = Math.round((total - expected) * 10000) / 10000;

            varianceCell.textContent = money.format(Math.abs(difference));
            varianceLabel.textContent = difference < -0.0001
                ? LABELS.short
                : (difference > 0.0001 ? LABELS.over : LABELS.difference);
            varianceCell.className = 'dl-total-value ' + (
                difference < -0.0001 ? 'text-rose-600'
                    : (difference > 0.0001 ? 'text-amber-700' : 'text-emerald-700')
            );
        }

        form.addEventListener('input', function (event) {
            if (event.target.matches('[data-denom], [data-closing-amount]')) render();
        });

        render();
    })();
</script>
@endpush
@endsection
