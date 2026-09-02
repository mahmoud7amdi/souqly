@extends('layouts.app')
@section('title', $contact->full_name)
@section('page_title', $contact->full_name_with_business)

@section('content')

{{-- Head carries what the header cannot: which kind of contact this is, and the
     two records a clerk opens a contact to reach. --}}
<x-page-head>
    <x-slot:subtitle>
        <span class="badge-muted">{{ __('lang_v1.'.$contact->type) }}</span>
        @if ($contact->mobile)
            <span class="ms-2 force-ltr">{{ $contact->mobile }}</span>
        @endif
    </x-slot:subtitle>

    <a href="{{ route('contacts.ledger', $contact->id) }}" class="btn-secondary">
        <x-nav-icon name="book"/>
        {{ __('lang_v1.ledger') }}
    </a>
    @if ($canSettle && $settleDue > 0)
        {{-- The accent button on this screen, and the only one: taking money is
             what the page is opened for. Absent entirely when there is nothing
             outstanding — a disabled Settle button on a clear account invites the
             click that explains why it is disabled. --}}
        <button type="button" class="btn-accent" data-open-settle>
            <x-nav-icon name="cash"/>
            {{ __('lang_v1.settle_due') }}
        </button>
    @endif
    @can('customer.update')
        <a href="{{ route('contacts.edit', $contact->id) }}" class="btn-primary">
            <x-nav-icon name="edit"/>
            {{ __('lang_v1.edit') }}
        </a>
    @endcan
</x-page-head>

{{-- Money owed first: it is the reason this screen gets opened. Only net due is
     toned, and only when there is something outstanding — a zero balance is the
     normal state and colouring it trains the eye to ignore the colour. --}}
@php
    /* A negative net due and a positive advance balance are the same fact said
       twice, not two figures to be added.

       `netDuesFor()` counts the parent row of a settlement, so a customer who
       hands over 500 against 300 of invoices comes back as net due −200 *and*
       advance balance 200. Both are right, and showing both invites the reader
       to total them into a 400 that does not exist. So the credit is stated once,
       in the tile that is named for it, and net due floors at zero: what is
       outstanding is nothing, which is true. */
    $netDue = max(0.0, (float) $summary['net_due']);
@endphp
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('lang_v1.net_due')"
                :value="format_currency($netDue)"
                icon="wallet"
                :tone="$netDue > 0 ? 'danger' : null"/>

        <x-stat :label="__('lang_v1.advance_balance')"
                :value="format_currency($summary['advance_balance'])"
                icon="coins"
                :tone="$summary['advance_balance'] > 0 ? 'success' : null"/>

        <x-stat :label="__('lang_v1.total_sales')"
                :value="format_currency($summary['sales_total'])"
                icon="receipt"
                :hint="trans_choice('lang_v1.invoice_count', $summary['sales_count'], ['count' => $summary['sales_count']])"/>

        <x-stat :label="__('lang_v1.total_purchases')"
                :value="format_currency($summary['purchases_total'])"
                icon="truck"
                :hint="trans_choice('lang_v1.invoice_count', $summary['purchases_count'], ['count' => $summary['purchases_count']])"/>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-3">

    <x-panel :title="__('lang_v1.contact_details')" icon="user" class="self-start">
        <x-attr-list :items="[
            'lang_v1.type' => __('lang_v1.'.$contact->type),
            'lang_v1.contact_id' => $contact->contact_id,
            'lang_v1.mobile' => $contact->mobile,
            'lang_v1.email' => $contact->email,
            'lang_v1.tax_number' => $contact->tax_number,
            'lang_v1.customer_group' => $contact->customer_group->name ?? null,
            'lang_v1.credit_limit' => $contact->credit_limit ? format_currency($contact->credit_limit) : null,
            'lang_v1.address' => $contact->contact_address,
        ]"/>

        @can('customer.update')
            {{-- .toolbar, not .card-actions: that one is a card footer with its own
                 border and grey fill, and this sits inside the body. --}}
            <div class="toolbar mt-5 border-t border-slate-100 pt-5">
                <a href="{{ route('contacts.openingBalance.edit', $contact->id) }}"
                   class="btn-secondary btn-sm">
                    <x-nav-icon name="cash" :size="4"/>
                    {{ __('lang_v1.opening_balance') }}
                </a>
            </div>
        @endcan
    </x-panel>

    <x-panel :title="__('lang_v1.recent_transactions')" icon="clock"
             class="lg:col-span-2" flush>
        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.date') }}</th>
                        <th>{{ __('lang_v1.reference_no') }}</th>
                        <th>{{ __('lang_v1.type') }}</th>
                        <th>{{ __('lang_v1.payment_status') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentTransactions as $movement)
                        <tr>
                            <td class="whitespace-nowrap">@format_date($movement['date'])</td>
                            <td class="force-ltr">{{ $movement['reference'] }}</td>
                            {{-- The method rides along with the movement kind rather
                                 than in the status column, which belongs to documents:
                                 a row headed "Payment status" reading "Cash" is not a
                                 status. This is also where a spend against stored
                                 credit announces itself, as "Advance". --}}
                            <td>
                                <span class="badge-muted">{{ $movement['label'] }}</span>
                                @if ($movement['method'])
                                    <span class="ms-1 text-xs text-slate-500">{{ $movement['method'] }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($movement['status'])
                                    @payment_status($movement['status'])
                                @else
                                    <span class="text-slate-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="cell-numeric">@format_currency($movement['total'])</td>
                        </tr>
                    @empty
                        <x-table-empty :columns="5" icon="receipt"
                                       :title="__('lang_v1.no_transactions_yet')"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>
</div>
@endsection

{{-- Pushed to the layout's modal stack rather than left inside the grid: a
     dialog is fixed-position and z-50, so nesting it in a column would put it
     inside a stacking context that the backdrop is supposed to cover. --}}
@if ($canSettle && $settleDue > 0)
    @push('modals')
        @include('contact._settle-modal')
    @endpush
@endif
