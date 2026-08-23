@extends('layouts.app')
@section('title', $document->ref_no)
@section('page_title', __('lang_v1.'.$document->type).' — '.$document->ref_no)

@section('content')

@php
    $showLot = (bool) session('business.enable_lot_number');
    $columnCount = $showLot ? 5 : 4;

    /* A fully settled invoice should not advertise "Add payment" as the main
       action of the screen — there is nothing left to pay. So the primary slot
       goes to whichever action is actually next. */
    $paymentIsPrimary = $due > 0;
@endphp

{{-- The document's identity, and everything you can do to it. The sticky header
     already names the type and reference, so the head carries the three facts a
     clerk checks first — who, when, where — and the two badges. --}}
<x-page-head :back="route($prefix.'.index')" :backLabel="__('lang_v1.'.$document->type.'s')">
    <x-slot:subtitle>
        <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="font-medium text-slate-700">
                {{ or_dash($document->contact->full_name_with_business ?? null) }}
            </span>
            <span class="text-slate-300">&middot;</span>
            <span class="force-ltr">@format_datetime($document->transaction_date)</span>
            <span class="text-slate-300">&middot;</span>
            <span>{{ or_dash($document->location->name ?? null) }}</span>
            <span class="ms-1 inline-flex items-center gap-1.5">
                @transaction_status($document->status)
                @payment_status($document->payment_status)
            </span>
        </span>
    </x-slot:subtitle>

    <button type="button" onclick="window.print()" class="btn-secondary">
        <x-nav-icon name="printer"/>
        {{ __('lang_v1.print') }}
    </button>

    @if ($document->type === 'purchase_order')
        <a href="{{ route('purchase-order.pdf', $document->id) }}" class="btn-secondary">
            <x-nav-icon name="download"/>
            {{ __('lang_v1.download_pdf') }}
        </a>
    @endif

    @can('purchase.update')
        @if ($document->canBeEdited())
            <a href="{{ route($prefix.'.edit', $document->id) }}" class="btn-secondary">
                <x-nav-icon name="edit"/>
                {{ __('lang_v1.edit') }}
            </a>
        @endif
    @endcan

    @if ($document->type === 'purchase')
        @can('purchase.update')
            <a href="{{ route('purchase-return.create', $document->id) }}" class="btn-secondary">
                <x-nav-icon name="undo"/>
                {{ __('lang_v1.add_return') }}
            </a>
        @endcan

        @can('purchase.payments')
            {{-- Route::has: the payments module lands in a later stage, and this
                 screen must not 500 before it exists. --}}
            @if (Route::has('payments.create'))
                <a href="{{ route('payments.create', ['transaction_id' => $document->id]) }}"
                   class="{{ $paymentIsPrimary ? 'btn-primary' : 'btn-secondary' }}">
                    <x-nav-icon name="cash"/>
                    {{ __('lang_v1.add_payment') }}
                </a>
            @endif
        @endcan
    @endif
</x-page-head>

<div class="grid gap-6 lg:grid-cols-4">

    <x-panel :title="__('lang_v1.items')" icon="box"
             :count="$document->purchase_lines->count()"
             class="lg:col-span-3" flush>
        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.product') }}</th>
                        @if ($showLot)
                            <th>{{ __('lang_v1.lot_number') }}</th>
                        @endif
                        <th class="th-numeric">{{ __('lang_v1.quantity') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.unit_cost') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.subtotal') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($document->purchase_lines as $line)
                        <tr>
                            <td>
                                <span class="cell-primary">{{ $line->variations->full_name }}</span>
                                <span class="cell-meta force-ltr">{{ $line->variations->sub_sku }}</span>
                            </td>
                            @if ($showLot)
                                <td class="force-ltr">{{ or_dash($line->lot_number) }}</td>
                            @endif
                            <td class="cell-numeric">
                                @format_quantity($line->quantity)
                                @if ($line->quantity_returned > 0)
                                    {{-- A bare "−3" under a quantity is a puzzle; the
                                         word is what makes it a fact. --}}
                                    <span class="cell-meta text-rose-500">
                                        {{ __('lang_v1.returned') }}
                                        &minus;@format_quantity($line->quantity_returned)
                                    </span>
                                @endif
                            </td>
                            <td class="cell-numeric">@format_currency($line->purchase_price_inc_tax)</td>
                            <td class="cell-numeric">
                                @format_currency($line->quantity * $line->purchase_price_inc_tax)
                            </td>
                        </tr>
                    @empty
                        <x-table-empty :columns="$columnCount" icon="box"
                                       :title="__('lang_v1.no_records_found')"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>

    <div class="grid gap-6 self-start">

        <x-panel :title="__('lang_v1.summary')" icon="receipt">
            <dl class="dl">
                @foreach ([
                    'lang_v1.subtotal' => $document->total_before_tax,
                    'lang_v1.discount' => $document->discount_amount,
                    'lang_v1.tax' => $document->tax_amount,
                    'lang_v1.shipping_charges' => $document->shipping_charges,
                ] as $key => $value)
                    <div class="dl-row">
                        <dt class="dl-key">{{ __($key) }}</dt>
                        <dd class="dl-value">@format_currency($value)</dd>
                    </div>
                @endforeach

                <div class="dl-total">
                    <dt class="font-semibold text-slate-900">{{ __('lang_v1.total') }}</dt>
                    <dd class="dl-total-value">@format_currency($document->final_total)</dd>
                </div>

                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.paid') }}</dt>
                    <dd class="dl-value text-emerald-600">@format_currency($paid)</dd>
                </div>

                {{-- The only figure on the screen that is a liability, so it is the
                     only one that is toned — and only while it is outstanding. --}}
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.due') }}</dt>
                    <dd @class(['dl-value', 'font-semibold text-rose-600' => $due > 0])>
                        @format_currency($due)
                    </dd>
                </div>
            </dl>
        </x-panel>

        @if ($document->terms->isNotEmpty())
            <x-panel :title="__('lang_v1.instalments')" icon="calendar"
                     :count="$document->terms->count()">
                <dl class="dl">
                    @foreach ($document->terms as $term)
                        <div class="dl-row">
                            <dt class="dl-key">
                                <span class="force-ltr">{{ $term->payment_term }}%</span>
                                <span class="text-slate-300">&middot;</span>
                                @format_date($term->due_date)
                            </dt>
                            <dd class="dl-value">@format_currency($term->amount)</dd>
                        </div>
                    @endforeach
                </dl>
            </x-panel>
        @endif

        @if ($document->payment_lines->isNotEmpty())
            <x-panel :title="__('lang_v1.payments')" icon="cash"
                     :count="$document->payment_lines->count()">
                <dl class="dl">
                    @foreach ($document->payment_lines as $payment)
                        <div class="dl-row">
                            <dt class="min-w-0">
                                <span class="block text-slate-700">{{ __('lang_v1.'.$payment->method) }}</span>
                                <span class="cell-meta">
                                    @format_date($payment->paid_on)
                                    @if ($payment->payment_account)
                                        <span class="text-slate-300">&middot;</span>
                                        {{ $payment->payment_account->name }}
                                    @endif
                                </span>
                            </dt>
                            <dd class="dl-value">@format_currency($payment->amount)</dd>
                        </div>
                    @endforeach
                </dl>
            </x-panel>
        @endif
    </div>
</div>
@endsection
