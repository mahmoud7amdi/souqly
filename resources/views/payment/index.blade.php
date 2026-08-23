@extends('layouts.app')
@section('title', __('lang_v1.payments'))
@section('page_title', __('lang_v1.payments'))

@section('content')

@php
    /* Parent rows only, per the controller: a contact settlement writes one
       parent plus a child per document it covered, so listing both would count
       the same money twice. That is why a row here can legitimately have no
       document of its own — it paid several. */
    $isFiltered = collect(['search', 'contact_id', 'method', 'account_id',
                           'direction', 'start_date', 'end_date'])
        ->contains(fn ($key) => request()->filled($key));

    $canAdd = auth()->user()->canAny(['sell.payments', 'purchase.payments']);
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $records->total(), ['count' => $records->total()])">
    @if ($canAdd)
        <a href="{{ route('payments.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.add_payment') }}
        </a>
    @endif
</x-page-head>

{{-- Two figures, because a payments ledger answers exactly two questions: what
     came in and what went out. A net line would hide both behind one number that
     is meaningful to nobody. --}}
<div class="section">
    <div class="grid gap-4 sm:grid-cols-2">
        <x-stat :label="__('lang_v1.money_in')"
                :value="format_currency($totals['in'])"
                icon="arrow-back"
                :hint="__('lang_v1.received_from_customers')"/>

        <x-stat :label="__('lang_v1.money_out')"
                :value="format_currency($totals['out'])"
                icon="arrow-forward"
                :hint="__('lang_v1.paid_to_suppliers')"/>
    </div>
</div>

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field">
            <label for="search" class="label">{{ __('lang_v1.reference_no') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search" dir="ltr"
                       placeholder="{{ __('lang_v1.payment_or_cheque_no') }}">
            </div>
        </div>

        <div class="field">
            <label for="contact_id" class="label">{{ __('lang_v1.contact') }}</label>
            <select id="contact_id" name="contact_id" class="select">
                @foreach ($contacts as $id => $name)
                    <option value="{{ $id }}" @selected(request('contact_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="method" class="label">{{ __('lang_v1.payment_method') }}</label>
            <select id="method" name="method" class="select">
                @foreach ($methods as $value => $name)
                    <option value="{{ $value }}" @selected(request('method') === (string) $value)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="account_id" class="label">{{ __('lang_v1.account') }}</label>
            <select id="account_id" name="account_id" class="select">
                @foreach ($accounts as $id => $name)
                    <option value="{{ $id }}" @selected(request('account_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="direction" class="label">{{ __('lang_v1.direction') }}</label>
            <select id="direction" name="direction" class="select">
                @foreach ($directions as $value => $name)
                    <option value="{{ $value }}" @selected(request('direction') === (string) $value)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="start_date" class="label">{{ __('lang_v1.from') }}</label>
            <input type="date" id="start_date" name="start_date" value="{{ request('start_date') }}" class="input">
        </div>

        <div class="field">
            <label for="end_date" class="label">{{ __('lang_v1.to') }}</label>
            <input type="date" id="end_date" name="end_date" value="{{ request('end_date') }}" class="input">
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('payments.index') }}" class="btn-secondary">
                    <x-nav-icon name="x" :size="4"/>
                    {{ __('lang_v1.reset') }}
                </a>
            @endif
        </div>
    </div>
</form>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.date') }}</th>
                <th>{{ __('lang_v1.reference_no') }}</th>
                <th>{{ __('lang_v1.paid_for') }}</th>
                <th>{{ __('lang_v1.payment_method') }}</th>
                <th>{{ __('lang_v1.account') }}</th>
                <th class="th-numeric">{{ __('lang_v1.amount') }}</th>
                <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $payment)
                @php
                    /* A payment's contact is either its own (a settlement) or the
                       document's (a payment against one invoice). */
                    $who = $payment->contact ?? $payment->transaction?->contact;
                    $isReversal = (bool) $payment->is_return;
                @endphp
                <tr>
                    <td class="whitespace-nowrap">@format_date($payment->paid_on)</td>

                    <td>
                        <a href="{{ route('payments.show', $payment->id) }}" class="cell-link force-ltr">
                            {{ or_dash($payment->payment_ref_no) }}
                        </a>
                        @if ($payment->cheque_number)
                            <span class="cell-meta force-ltr">
                                {{ __('lang_v1.cheque_number') }} {{ $payment->cheque_number }}
                            </span>
                        @endif
                    </td>

                    {{-- Document and contact in one cell. They are one answer to
                         one question — what did this pay for — and two columns
                         would leave every settlement row half empty. --}}
                    <td>
                        @if ($payment->transaction)
                            <x-document-link :transaction="$payment->transaction"/>
                            <span class="cell-meta">{{ or_dash($who?->full_name_with_business) }}</span>
                        @else
                            <span class="cell-primary">{{ or_dash($who?->full_name_with_business) }}</span>
                            <span class="cell-meta">{{ __('lang_v1.balance_settlement') }}</span>
                        @endif
                    </td>

                    <td>{{ $payment->method_label }}</td>
                    <td>{{ or_dash($payment->payment_account->name ?? null) }}</td>

                    {{-- Direction sits under the figure rather than in a column of
                         its own: it qualifies the amount, and a reader scanning
                         money wants both in one glance. A reversal is the one case
                         that is toned, because it is the exception worth spotting. --}}
                    <td @class(['cell-numeric', 'text-rose-600' => $isReversal])>
                        @if ($isReversal)&minus;@endif@format_currency($payment->amount)
                        <span @class(['cell-meta', 'text-rose-500' => $isReversal])>
                            @if ($isReversal)
                                {{ __('lang_v1.payment_return') }}
                            @elseif ($payment->payment_type === 'debit')
                                {{ __('lang_v1.paid_out') }}
                            @else
                                {{ __('lang_v1.received') }}
                            @endif
                        </span>
                    </td>

                    <td>
                        <div class="cell-actions">
                            <a href="{{ route('payments.show', $payment->id) }}"
                               class="btn-icon" title="{{ __('lang_v1.view') }}"
                               aria-label="{{ __('lang_v1.view') }}">
                                <x-nav-icon name="eye" :size="4"/>
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="7"
                               :icon="$isFiltered ? 'search' : 'cash'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('lang_v1.payments_appear_when_recorded')">
                    @if ($isFiltered)
                        <a href="{{ route('payments.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canAdd)
                        <a href="{{ route('payments.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.add_payment') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $records->links() }}
</div>
@endsection
