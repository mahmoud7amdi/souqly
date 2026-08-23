@extends('layouts.app')
@section('title', $heading)
@section('page_title', $heading)

@section('content')

@php
    /* One listing, four flavours — invoices, drafts, quotations and sales orders.
       Each is a different question, so each gets a different set of columns
       rather than one union with blanks in it. */
    $isInvoices = $variant === 'invoices';
    $isSalesOrder = $type === 'sales_order';

    /* A draft or quotation can be finalised from the list: it is the only thing
       anyone wants to do with one, and making them open the document first adds a
       click to the most common action on the screen. */
    $canConvert = in_array($variant, ['drafts', 'quotations'], true) && $canCreate;

    /* Money owed is a fact about an invoice. A quotation nobody has accepted and
       an order nobody has invoiced are not receivables, so those flavours show
       what was quoted and nothing about payment. */
    $showMoneyOwed = $isInvoices;

    $isFiltered = collect(['search', 'contact_id', 'location_id', 'status',
                           'payment_status', 'shipping_status', 'start_date', 'end_date'])
        ->contains(fn ($key) => request()->filled($key));

    $columnCount = 5
        + ($isInvoices ? 3 : 0)
        + ($isSalesOrder ? 1 : 0)
        + 1;
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $documents->total(), ['count' => $documents->total()])">
    @if ($canCreate)
        {{-- The POS is the faster road to a counter sale, so on the invoice list it
             sits beside the form as a peer rather than being hidden in the nav. --}}
        @if ($isInvoices && Route::has('pos.create'))
            <a href="{{ route('pos.create') }}" class="btn-secondary">
                <x-nav-icon name="pos"/>
                {{ __('lang_v1.pos') }}
            </a>
        @endif

        <a href="{{ route($prefix.'.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.add') }}
        </a>
    @endif
</x-page-head>

{{-- Total, paid, due for invoices; a lone total for the flavours where the other
     two would be a claim about money that has not been agreed yet. --}}
<div class="section">
    <div @class(['grid gap-4', 'sm:grid-cols-3' => $showMoneyOwed])>
        <x-stat :label="__('lang_v1.total')" :value="format_currency($totals['total'])" icon="receipt"/>

        @if ($showMoneyOwed)
            <x-stat :label="__('lang_v1.paid')" :value="format_currency($totals['paid'])" icon="check-circle"/>
            <x-stat :label="__('lang_v1.due')"
                    :value="format_currency($totals['due'])"
                    icon="wallet"
                    :tone="$totals['due'] > 0 ? 'danger' : null"/>
        @endif
    </div>
</div>

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field">
            <label for="search" class="label">{{ __('lang_v1.invoice_no') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search" dir="ltr">
            </div>
        </div>

        <div class="field">
            <label for="contact_id" class="label">{{ __('lang_v1.customer') }}</label>
            <select id="contact_id" name="contact_id" class="select">
                @foreach ($customers as $id => $name)
                    <option value="{{ $id }}" @selected(request('contact_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="location_id" class="label">{{ __('lang_v1.location') }}</label>
            <select id="location_id" name="location_id" class="select">
                @foreach ($locations as $id => $name)
                    <option value="{{ $id }}" @selected(request('location_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        {{-- Only sales orders have a status worth filtering on: the other three
             flavours are defined BY their status, so a status filter there would
             offer to narrow a list to itself. --}}
        @if ($isSalesOrder)
            <div class="field">
                <label for="status" class="label">{{ __('lang_v1.order_status') }}</label>
                <select id="status" name="status" class="select">
                    <option value="">{{ __('lang_v1.all') }}</option>
                    @foreach ($statuses as $value => $name)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($isInvoices)
            <div class="field">
                <label for="payment_status" class="label">{{ __('lang_v1.payment_status') }}</label>
                <select id="payment_status" name="payment_status" class="select">
                    <option value="">{{ __('lang_v1.all') }}</option>
                    @foreach (['paid', 'partial', 'due'] as $value)
                        <option value="{{ $value }}" @selected(request('payment_status') === $value)>
                            {{ __('lang_v1.'.$value) }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

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
                <a href="{{ route($prefix.'.index') }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.invoice_no') }}</th>
                <th>{{ __('lang_v1.customer') }}</th>
                <th>{{ __('lang_v1.location') }}</th>
                @if ($isSalesOrder)
                    <th>{{ __('lang_v1.order_status') }}</th>
                @endif
                @if ($isInvoices)
                    <th>{{ __('lang_v1.payment_status') }}</th>
                    <th>{{ __('lang_v1.shipping') }}</th>
                @endif
                <th class="th-numeric">{{ __('lang_v1.total') }}</th>
                @if ($isInvoices)
                    <th class="th-numeric">{{ __('lang_v1.due') }}</th>
                @endif
                <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($documents as $document)
                <tr>
                    <td class="whitespace-nowrap">@format_date($document->transaction_date)</td>
                    <td class="force-ltr">
                        <a href="{{ route($prefix.'.show', $document->id) }}" class="cell-link">
                            {{ $document->invoice_no }}
                        </a>
                        @if ($document->ref_no)
                            <span class="cell-meta force-ltr">{{ $document->ref_no }}</span>
                        @endif
                    </td>
                    <td>{{ or_dash($document->contact->full_name_with_business ?? null) }}</td>
                    <td>{{ or_dash($document->location->name ?? null) }}</td>

                    @if ($isSalesOrder)
                        <td>@transaction_status($document->status)</td>
                    @endif

                    @if ($isInvoices)
                        <td>@payment_status($document->payment_status)</td>
                        {{-- Blank, not a pill reading "none": a sale collected at the
                             counter was never a shipment, and giving it a shipping
                             badge would put it in a queue it does not belong to. --}}
                        <td>
                            @if ($document->shipping_status)
                                @transaction_status($document->shipping_status)
                            @else
                                {{ or_dash(null) }}
                            @endif
                        </td>
                    @endif

                    <td class="cell-numeric">@format_currency($document->final_total)</td>

                    @if ($isInvoices)
                        <td @class(['cell-numeric', 'font-semibold text-rose-600' => $document->due_amount > 0])>
                            @format_currency($document->due_amount)
                        </td>
                    @endif

                    <td>
                        <div class="cell-actions">
                            @if ($canConvert)
                                {{-- A POST, because this is the moment stock leaves. --}}
                                <form method="POST" action="{{ route('sells.convert', $document->id) }}">
                                    @csrf
                                    <button type="submit" class="btn-icon"
                                            title="{{ __('lang_v1.convert_to_invoice') }}"
                                            aria-label="{{ __('lang_v1.convert_to_invoice') }}">
                                        <x-nav-icon name="check-circle" :size="4"/>
                                    </button>
                                </form>
                            @endif

                            <a href="{{ route($prefix.'.show', $document->id) }}"
                               class="btn-icon" title="{{ __('lang_v1.view') }}"
                               aria-label="{{ __('lang_v1.view') }}">
                                <x-nav-icon name="eye" :size="4"/>
                            </a>

                            @if ($canUpdate && $document->canBeEdited())
                                <a href="{{ route($prefix.'.edit', $document->id) }}"
                                   class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                   aria-label="{{ __('lang_v1.edit') }}">
                                    <x-nav-icon name="edit" :size="4"/>
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="$columnCount"
                               :icon="$isFiltered ? 'search' : 'receipt'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : null">
                    @if ($isFiltered)
                        <a href="{{ route($prefix.'.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canCreate)
                        <a href="{{ route($prefix.'.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.add') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $documents->links() }}
</div>
@endsection
