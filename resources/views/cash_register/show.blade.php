@extends('layouts.app')
@section('title', __('lang_v1.register_session').' #'.$register->id)
@section('page_title', __('lang_v1.cash_register'))

@section('content')

@php
    $isOpen = $register->isOpen();
    $methodLabels = \App\Support\TransactionTypes::paymentMethods();

    /* The whole point of counting a drawer: what the till says should be there
       against what was actually found. Only meaningful once it has been counted,
       and only cash — a card slip is a promise, not a note in the drawer. */
    $variance = $isOpen ? null : round((float) $register->closing_amount - $summary['cash_in_hand'], 4);
    $isShort = $variance !== null && $variance < -0.0001;
    $isOver = $variance !== null && $variance > 0.0001;

    $denominations = (array) ($register->denominations ?? []);

    /* Payouts only earn a card on a shift that had one. Most do not, and a
       permanent zero would spend a fifth of the row saying "nothing happened". */
    $hasPayouts = abs($summary['payouts']) > 0.0001;
@endphp

<x-page-head :title="'#'.$register->id" :back="route('cash-register.index')"
             :backLabel="__('lang_v1.cash_register')">
    <x-slot:subtitle>
        {{ or_dash($register->user->user_full_name ?? null) }}
        <span class="text-slate-300">·</span>
        {{ $register->location->name ?? __('lang_v1.all_locations') }}
        <span class="text-slate-300">·</span>
        {{ __('lang_v1.opened_at') }} @format_datetime($register->created_at)
        @if ($isOpen)
            <span class="badge-success ms-1">{{ __('lang_v1.open') }}</span>
        @else
            <span class="badge-muted ms-1">{{ __('lang_v1.closed') }}</span>
        @endif
    </x-slot:subtitle>

    @if ($isOpen)
        <a href="{{ route('pos.create') }}" class="btn-secondary">
            <x-nav-icon name="pos" :size="4"/>
            {{ __('lang_v1.back_to_selling') }}
        </a>
    @endif

    @if ($canClose)
        <a href="{{ route('cash-register.closeForm', $register->id) }}" class="btn-accent">
            <x-nav-icon name="calculator" :size="4"/>
            {{ __('lang_v1.close_register') }}
        </a>
    @endif
</x-page-head>

<div class="section">
    <div @class([
        'rise-group grid gap-4 sm:grid-cols-2',
        'xl:grid-cols-4' => ! $hasPayouts,
        'xl:grid-cols-5' => $hasPayouts,
    ])>
        <x-stat :label="__('lang_v1.opening_float')" :value="format_currency($summary['opening'])" icon="wallet"/>

        <x-stat :label="__('lang_v1.total_collected')"
                :value="format_currency($summary['total_collected'])"
                icon="coins"
                :hint="trans_choice('lang_v1.across_n_sales', $summary['sales_count'], ['count' => $summary['sales_count']])"/>

        {{-- The figure the cashier is about to be asked to match, so it is given
             the same prominence as the total and named the same way as on the
             close screen. --}}
        <x-stat :label="__('lang_v1.cash_in_hand')"
                :value="format_currency($summary['cash_in_hand'])"
                icon="cash"
                :hint="__('lang_v1.cash_only_not_cards')"/>

        <x-stat :label="__('lang_v1.refunds')"
                :value="format_currency($summary['refunds'])"
                icon="undo"
                :tone="$summary['refunds'] > 0 ? 'warning' : null"/>

        @if ($hasPayouts)
            <x-stat :label="__('lang_v1.paid_out')"
                    :value="format_currency($summary['payouts'])"
                    icon="minus-circle"
                    tone="warning"
                    :hint="__('lang_v1.paid_out_of_this_drawer')"/>
        @endif
    </div>
</div>

<div class="section grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <x-panel :title="__('lang_v1.by_payment_method')" icon="card"
                 :subtitle="__('lang_v1.what_the_shift_took_in')">
            @if (empty($summary['by_method']))
                <x-empty-state icon="coins" compact
                               :title="__('lang_v1.nothing_taken_yet')"
                               :text="__('lang_v1.payments_appear_as_you_sell')"/>
            @else
                <dl class="dl">
                    @foreach ($summary['by_method'] as $method => $amount)
                        <div class="dl-row">
                            <dt class="dl-key">
                                {{ isset($methodLabels[$method]) ? __($methodLabels[$method]) : $method }}
                            </dt>
                            <dd @class(['dl-value', 'text-amber-700' => $amount < 0])>
                                @format_currency($amount)
                            </dd>
                        </div>
                    @endforeach

                    <div class="dl-total">
                        <dt class="dl-key">{{ __('lang_v1.total_collected') }}</dt>
                        <dd class="dl-total-value">@format_currency($summary['total_collected'])</dd>
                    </div>
                </dl>

                @if (abs($summary['transfers']) > 0.0001)
                    {{-- Transfers out of the drawer are not takings and are kept out
                         of the method breakdown on purpose, so they are stated
                         separately rather than quietly missing from the total. --}}
                    <p class="hint mt-4">
                        {{ __('lang_v1.transfers_out_of_the_drawer') }}:
                        <span class="tabular">@format_currency($summary['transfers'])</span>
                    </p>
                @endif
            @endif
        </x-panel>
    </div>

    <div class="space-y-6">
        @if (! $isOpen)
            <x-panel :title="__('lang_v1.the_count')" icon="calculator" class="self-start"
                     :subtitle="format_datetime($register->closed_at)">
                <p @class([
                    'stat-value text-3xl',
                    'text-rose-600' => $isShort,
                    'text-amber-700' => $isOver,
                ])>@format_currency($register->closing_amount)</p>
                <p class="stat-hint mt-1">{{ __('lang_v1.counted_in_the_drawer') }}</p>

                <dl class="dl mt-5">
                    <div class="dl-row">
                        <dt class="dl-key">{{ __('lang_v1.cash_in_hand') }}</dt>
                        <dd class="dl-value">@format_currency($summary['cash_in_hand'])</dd>
                    </div>

                    <div class="dl-total">
                        <dt class="dl-key">
                            {{ $isShort ? __('lang_v1.short_by') : ($isOver ? __('lang_v1.over_by') : __('lang_v1.difference')) }}
                        </dt>
                        <dd @class([
                            'dl-total-value',
                            'text-rose-600' => $isShort,
                            'text-amber-700' => $isOver,
                            'text-emerald-700' => ! $isShort && ! $isOver,
                        ])>@format_currency(abs($variance))</dd>
                    </div>
                </dl>

                @if (! $isShort && ! $isOver)
                    <p class="hint mt-3">{{ __('lang_v1.drawer_balanced') }}</p>
                @endif

                @if ($register->total_card_slips || $register->total_cheques)
                    <x-attr-list :columns="1" class="mt-5" :items="[
                        'lang_v1.card_slips' => $register->total_card_slips ?: null,
                        'lang_v1.cheques' => $register->total_cheques ?: null,
                    ]"/>
                @endif

                @if ($register->closing_note)
                    <div class="mt-5">
                        <p class="section-label">{{ __('lang_v1.closing_note') }}</p>
                        <p class="text-sm text-slate-700">{{ $register->closing_note }}</p>
                    </div>
                @endif
            </x-panel>

            @if (! empty($denominations))
                <x-panel :title="__('lang_v1.denominations')" icon="hash" class="self-start">
                    <dl class="dl">
                        @foreach ($denominations as $value => $count)
                            <div class="dl-row">
                                <dt class="dl-key">
                                    <span class="tabular">{{ $value }}</span>
                                    <span class="text-slate-400">×</span>
                                    <span class="tabular">{{ $count }}</span>
                                </dt>
                                <dd class="dl-value">@format_currency((float) $value * (int) $count)</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-panel>
            @endif
        @else
            <x-panel :title="__('lang_v1.session')" icon="clock" class="self-start">
                <x-attr-list :columns="1" :items="[
                    'lang_v1.cashier' => $register->user->user_full_name ?? null,
                    'lang_v1.location' => $register->location->name ?? null,
                    'lang_v1.opened_at' => format_datetime($register->created_at),
                ]"/>

                <p class="hint mt-5">{{ __('lang_v1.count_the_drawer_to_close') }}</p>
            </x-panel>
        @endif
    </div>
</div>

<div class="section-head">
    <div class="section-head-text">
        <p class="section-eyebrow">{{ __('lang_v1.ledger') }}</p>
        <h2 class="section-title">{{ __('lang_v1.through_the_drawer') }}</h2>
        <p class="section-desc">{{ __('lang_v1.every_payment_that_passed_through') }}</p>
    </div>

    <div class="section-actions">
        <span class="text-sm text-slate-500">
            {{ trans_choice('lang_v1.record_count', $entries->total(), ['count' => $entries->total()]) }}
        </span>
    </div>
</div>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.time') }}</th>
                <th>{{ __('lang_v1.kind') }}</th>
                <th>{{ __('lang_v1.document') }}</th>
                <th>{{ __('lang_v1.method') }}</th>
                <th class="th-numeric">{{ __('lang_v1.money_in') }}</th>
                <th class="th-numeric">{{ __('lang_v1.money_out') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                @php
                    $isIn = $entry->type === 'credit';
                    $kind = match ($entry->transaction_type) {
                        'initial' => __('lang_v1.opening_float'),
                        'refund' => __('lang_v1.refund'),
                        'transfer' => __('lang_v1.transfer'),
                        'payout' => __('lang_v1.payout'),
                        default => __('lang_v1.sale'),
                    };
                @endphp
                <tr>
                    <td class="whitespace-nowrap">@format_time($entry->created_at)</td>

                    <td><span class="cell-primary">{{ $kind }}</span></td>

                    <td><x-document-link :transaction="$entry->transaction"/></td>

                    <td>
                        {{ isset($methodLabels[$entry->pay_method])
                            ? __($methodLabels[$entry->pay_method])
                            : or_dash($entry->pay_method) }}
                    </td>

                    <td class="cell-numeric text-emerald-700">
                        {{ $isIn ? format_currency($entry->amount) : '' }}
                    </td>

                    <td class="cell-numeric text-amber-700">
                        {{ $isIn ? '' : format_currency($entry->amount) }}
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="6" icon="receipt"
                               :title="__('lang_v1.nothing_here_yet')"
                               :text="__('lang_v1.payments_appear_as_you_sell')"/>
            @endforelse
        </tbody>
    </table>

    {{ $entries->links() }}
</div>
@endsection
