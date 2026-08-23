@extends('layouts.app')
@section('title', __('lang_v1.payment'))
@section('page_title', __('lang_v1.payment'))

@section('content')

@php
    $isReversal = (bool) $payment->is_return;
    $isAdvance = $payment->method === 'advance' || (bool) $payment->is_advance;

    /* Its own contact for a settlement, the document's for a single payment. */
    $who = $payment->contact ?? $payment->transaction?->contact;

    /* A settlement writes one parent plus a child per invoice it covered, so this
       is the allocation — the only place a user can see where their money went. */
    $children = $payment->child_payments;

    /* A child row is a slice of a decision made elsewhere, and an advance payment
       is the contact's balance being spent: the controller refuses to edit either,
       so the buttons should not offer to. */
    $isLocked = $isAdvance || ! empty($payment->parent_id);

    $detail = match (true) {
        $payment->method === 'card' => array_filter([
            'lang_v1.card_number' => $payment->card_number,
            'lang_v1.card_holder_name' => $payment->card_holder_name,
            'lang_v1.card_transaction_number' => $payment->card_transaction_number,
            'lang_v1.card_type' => $payment->card_type,
            'lang_v1.card_expiry' => $payment->card_month && $payment->card_year
                ? $payment->card_month.'/'.$payment->card_year
                : null,
        ]),
        $payment->method === 'cheque' => array_filter([
            'lang_v1.cheque_number' => $payment->cheque_number,
        ]),
        $payment->method === 'bank_transfer' => array_filter([
            'lang_v1.bank_account_number' => $payment->bank_account_number,
        ]),
        default => array_filter([
            'lang_v1.transaction_no' => $payment->transaction_no,
        ]),
    };
@endphp

<x-page-head :back="route('payments.index')" :backLabel="__('lang_v1.payments')">
    <x-slot:subtitle>
        <span class="force-ltr">{{ or_dash($payment->payment_ref_no) }}</span>
        <span class="text-slate-300">&middot;</span>
        @format_date($payment->paid_on)
        <span class="text-slate-300">&middot;</span>
        {{ $payment->method_label }}

        @if ($isReversal)
            <span class="badge-danger">{{ __('lang_v1.payment_return') }}</span>
        @endif
        @if ($isAdvance)
            <span class="badge-brand">{{ __('lang_v1.advance') }}</span>
        @endif
        @if ($payment->paid_through_link)
            <span class="badge-muted">{{ __('lang_v1.paid_online') }}</span>
        @endif
    </x-slot:subtitle>

    @if ($canUpdate && ! $isLocked)
        <a href="{{ route('payments.edit', $payment->id) }}" class="btn-secondary">
            <x-nav-icon name="edit" :size="4"/>
            {{ __('lang_v1.edit') }}
        </a>
    @endif

    @if ($canDelete)
        <form method="POST" action="{{ route('payments.destroy', $payment->id) }}"
              data-confirm="{{ __('lang_v1.confirm_delete') }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-danger">
                <x-nav-icon name="trash" :size="4"/>
                {{ __('lang_v1.delete') }}
            </button>
        </form>
    @endif
</x-page-head>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="grid gap-6 self-start lg:col-span-2">

        {{-- ============ What this paid for ============ --}}
        <x-panel :title="__('lang_v1.paid_for')" icon="receipt">
            @if ($payment->transaction)
                <div class="surface-quiet">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <x-document-link :transaction="$payment->transaction"/>
                        <span class="text-sm text-slate-500">
                            {{ __('lang_v1.'.$payment->transaction->type) }}
                        </span>
                    </div>

                    <dl class="dl mt-3">
                        <div class="dl-row">
                            <dt class="dl-key">{{ __('lang_v1.total') }}</dt>
                            <dd class="dl-value">@format_currency($payment->transaction->final_total)</dd>
                        </div>
                        <div class="dl-row">
                            <dt class="dl-key">{{ __('lang_v1.payment_status') }}</dt>
                            <dd>@payment_status($payment->transaction->payment_status)</dd>
                        </div>
                        @if ($payment->transaction->location)
                            <div class="dl-row">
                                <dt class="dl-key">{{ __('lang_v1.business_location') }}</dt>
                                <dd class="text-slate-800">{{ $payment->transaction->location->name }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @else
                {{-- No document of its own is the normal shape for a settlement,
                     not missing data — so it is stated, not left blank. --}}
                <div class="surface-quiet">
                    <p class="font-semibold text-slate-900">{{ __('lang_v1.balance_settlement') }}</p>
                    <p class="hint">{{ __('lang_v1.settlement_allocates_oldest_first') }}</p>
                </div>
            @endif

            <div class="mt-5">
                <x-attr-list :columns="2" :items="[
                    'lang_v1.contact' => $who?->full_name_with_business,
                    'lang_v1.account' => $payment->payment_account->name ?? null,
                    'lang_v1.direction' => $payment->payment_type === 'debit'
                        ? __('lang_v1.paid_out')
                        : __('lang_v1.received'),
                    'lang_v1.added_by' => $payment->created_user?->user_full_name,
                ]"/>
            </div>

            @if (! empty($detail))
                <div class="mt-5">
                    <p class="section-label">{{ $payment->method_label }}</p>
                    <x-attr-list :columns="2" :items="$detail"/>
                </div>
            @endif

            @if ($payment->note)
                <div class="mt-5">
                    <p class="section-label">{{ __('lang_v1.note') }}</p>
                    <p class="text-sm text-slate-700">{{ $payment->note }}</p>
                </div>
            @endif

            @if ($payment->document)
                <div class="mt-5">
                    <a href="{{ $payment->document_path }}" target="_blank" rel="noopener" class="link">
                        <x-nav-icon name="document" :size="4"/>
                        {{ $payment->document_name }}
                    </a>
                </div>
            @endif
        </x-panel>

        {{-- ============ How it was allocated ============
             Only a settlement has children. Flush, because the content is a table
             that draws its own padding. --}}
        @if ($children->isNotEmpty())
            <x-panel :title="__('lang_v1.allocation')" icon="split"
                     :count="$children->count()"
                     :subtitle="__('lang_v1.one_row_per_document_covered')" flush>
                <div class="table-wrap table-flush">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('lang_v1.document') }}</th>
                                <th>{{ __('lang_v1.date') }}</th>
                                <th class="th-numeric">{{ __('lang_v1.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($children as $child)
                                <tr>
                                    <td><x-document-link :transaction="$child->transaction"/></td>
                                    <td class="whitespace-nowrap">@format_date($child->paid_on)</td>
                                    <td class="cell-numeric">@format_currency($child->amount)</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endif
    </div>

    {{-- ============ The figure ============
         One number, given the room it deserves: on a payment screen "how much"
         is the whole point, and burying it in a row of a definition list makes
         the reader hunt for it. --}}
    <x-panel :title="__('lang_v1.amount')" icon="cash" class="self-start">
        {{-- `text-3xl` needs no `!important` to beat `.stat-value`'s own size:
             Tailwind 4 puts the utilities layer after components, so the utility
             wins on layer order alone. The v3 spelling `!text-3xl` would not even
             be recognised — it silently generates nothing. --}}
        <p @class(['stat-value text-3xl', 'text-rose-600' => $isReversal])>
            @if ($isReversal)&minus;@endif@format_currency($payment->amount)
        </p>

        <p class="stat-hint mt-1">
            {{ $isReversal ? __('lang_v1.payment_return') : $payment->method_label }}
        </p>

        <dl class="dl mt-5">
            <div class="dl-row">
                <dt class="dl-key">{{ __('lang_v1.paid_on') }}</dt>
                <dd class="dl-value">@format_date($payment->paid_on)</dd>
            </div>
            <div class="dl-row">
                <dt class="dl-key">{{ __('lang_v1.recorded_at') }}</dt>
                <dd class="dl-value">@format_datetime($payment->created_at)</dd>
            </div>
        </dl>

        @if ($isLocked)
            {{-- The reason, at the point where someone looks for the missing Edit
                 button. A disabled control with no explanation is worse than none. --}}
            <p class="hint mt-5">{{ __('lang_v1.payment_line_locked') }}</p>
        @endif
    </x-panel>
</div>
@endsection
