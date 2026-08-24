@extends('layouts.app')
@section('title', $document->invoice_no)
@section('page_title', __('lang_v1.'.$document->type).' — '.$document->invoice_no)

@section('content')

@php
    $isSalesOrder = $document->type === 'sales_order';
    $typePlural = $isSalesOrder ? 'sales_orders' : 'sales';

    /* A draft or a quotation has not moved stock or money yet, so the one thing
       to do with it is make it real. */
    $isPending = $document->status === 'draft' || $document->is_quotation;

    /* A fully settled invoice should not advertise "Add payment" as the main
       action of the screen — there is nothing left to pay. So the primary slot
       goes to whichever action is actually next. */
    $paymentIsPrimary = $due > 0 && ! $isPending;

    $hasShipping = $document->shipping_status
        || $document->shipping_details
        || $document->delivered_to
        || $document->shipping_address;
@endphp

{{-- The document's identity, and everything you can do to it. The sticky header
     already names the type and number, so the head carries the three facts a
     clerk checks first — who, when, where — and the badges. --}}
<x-page-head :back="route($prefix.'.index')" :backLabel="__('lang_v1.'.$typePlural)">
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
                @if ($document->is_quotation)
                    <span class="badge-muted">
                        <x-nav-icon name="document" :size="3"/>
                        {{ __('lang_v1.quotation') }}
                    </span>
                @endif
                @transaction_status($document->status)
                @unless ($isSalesOrder || $isPending)
                    @payment_status($document->payment_status)
                @endunless
                @if ($document->shipping_status)
                    @transaction_status($document->shipping_status)
                @endif
            </span>
        </span>
    </x-slot:subtitle>

    <button type="button" onclick="window.print()" class="btn-secondary">
        <x-nav-icon name="printer"/>
        {{ __('lang_v1.print') }}
    </button>

    @if ($canUpdate && $document->canBeEdited())
        <a href="{{ route($prefix.'.edit', $document->id) }}" class="btn-secondary">
            <x-nav-icon name="edit"/>
            {{ __('lang_v1.edit') }}
        </a>
    @endif

    @unless ($isSalesOrder)
        @if ($isPending)
            {{-- POST, because this is the moment stock leaves. Primary while the
                 document is pending: nothing else on the screen matters until it
                 is either an invoice or abandoned. --}}
            <form method="POST" action="{{ route('sells.convert', $document->id) }}">
                @csrf
                <button type="submit" class="btn-primary">
                    <x-nav-icon name="check-circle"/>
                    {{ __('lang_v1.convert_to_invoice') }}
                </button>
            </form>
        @else
            @can('access_sell_return')
                <a href="{{ route('sell-return.create', $document->id) }}" class="btn-secondary">
                    <x-nav-icon name="undo"/>
                    {{ __('lang_v1.add_return') }}
                </a>
            @endcan

            @can('sell.payments')
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
    @endunless
</x-page-head>

{{-- The document itself: what was sold, and what it came to. One section, so the
     fulfilment block below reads as a separate matter rather than more of this. --}}
<div class="section grid gap-6 lg:grid-cols-4">

    <x-panel :title="__('lang_v1.items')" icon="box"
             :count="$document->sell_lines->count()"
             class="lg:col-span-3" flush>
        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.product') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.quantity') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.unit_price') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.subtotal') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($document->sell_lines as $line)
                        <tr>
                            <td>
                                <span class="cell-primary">{{ $line->variations->full_name }}</span>
                                <span class="cell-meta force-ltr">{{ $line->variations->sub_sku }}</span>
                                @if ($line->sell_line_note)
                                    <span class="cell-meta">{{ $line->sell_line_note }}</span>
                                @endif
                            </td>
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
                            <td class="cell-numeric">@format_currency($line->unit_price_inc_tax)</td>
                            <td class="cell-numeric">@format_currency($line->line_total)</td>
                        </tr>
                    @empty
                        <x-table-empty :columns="4" icon="box"
                                       :title="__('lang_v1.no_records_found')"/>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($document->additional_notes || $document->sales_person)
            <x-slot:footer>
                @if ($document->sales_person)
                    <span class="text-slate-500">
                        {{ __('lang_v1.sales_person') }}:
                        <span class="font-medium text-slate-700">{{ $document->sales_person->name }}</span>
                    </span>
                @endif
                @if ($document->additional_notes)
                    <span class="min-w-0 text-slate-600">{{ $document->additional_notes }}</span>
                @endif
            </x-slot:footer>
        @endif
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

                {{-- A quotation has no receivable: nobody has agreed to pay it, so
                     "paid 0 / due 300" would be stating a debt that does not
                     exist. Same for a sales order, which is a promise. --}}
                @unless ($isSalesOrder || $isPending)
                    <div class="dl-row">
                        <dt class="dl-key">{{ __('lang_v1.paid') }}</dt>
                        <dd class="dl-value text-emerald-600">@format_currency($paid)</dd>
                    </div>

                    {{-- The only figure on the screen that is a liability, so it is
                         the only one that is toned — and only while outstanding. --}}
                    <div class="dl-row">
                        <dt class="dl-key">{{ __('lang_v1.due') }}</dt>
                        <dd @class(['dl-value', 'font-semibold text-rose-600' => $due > 0])>
                            @format_currency($due)
                        </dd>
                    </div>
                @endunless
            </dl>
        </x-panel>

        @if ($isSalesOrder && $canUpdate)
            {{-- The order's own progress, which nothing else derives: a sales order
                 becomes partial or completed as invoices are raised against it, and
                 a human corrects it when the customer changes their mind.

                 The form wraps the panel rather than sitting inside it, here and in
                 the shipping panel below: the footer slot is rendered after the
                 default slot closes, so a <form> opened inside the body would leave
                 its own submit button outside itself. --}}
            <form method="POST" action="{{ route('sales-order.updateStatus', $document->id) }}">
                @csrf
                <x-panel :title="__('lang_v1.order_status')" icon="clipboard">
                    <div class="field">
                        <label for="order-status" class="label">{{ __('lang_v1.status') }}</label>
                        <select id="order-status" name="status" class="select" required>
                            @foreach ($statuses as $value => $name)
                                <option value="{{ $value }}" @selected($document->status === $value)>
                                    {{ $name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="hint">{{ __('lang_v1.order_status_hint') }}</p>
                    </div>

                    <x-slot:footer>
                        <button type="submit" class="btn-primary btn-sm">
                            <x-nav-icon name="save" :size="4"/>
                            {{ __('lang_v1.update_status') }}
                        </button>
                    </x-slot:footer>
                </x-panel>
            </form>
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

        @if ($document->return_parent)
            {{-- Goods came back. Small panel rather than a badge: the return has its
                 own document and its own total, and both are worth showing. --}}
            <x-panel :title="__('lang_v1.sell_return')" icon="undo">
                <dl class="dl">
                    <div class="dl-row">
                        <dt class="dl-key force-ltr">
                            <a href="{{ route('sell-return.show', $document->return_parent->id) }}" class="link">
                                {{ $document->return_parent->invoice_no }}
                            </a>
                        </dt>
                        <dd class="dl-value">@format_currency($document->return_parent->final_total)</dd>
                    </div>
                </dl>
            </x-panel>
        @endif
    </div>
</div>

@unless ($isSalesOrder)
    {{-- Shipping sits below the document rather than beside it: it is what happens
         after the invoice, and a form nested in the summary column would be too
         narrow for an address.

         The group is named by a section head rather than by the panel's own title,
         because it is a second subject on the screen and not a third card in the
         document's grid. The panel therefore carries no title — two headings for
         one block is exactly what the eyebrow exists to replace. --}}
    <div class="section-head">
        <div class="section-head-text">
            <p class="section-eyebrow">{{ __('lang_v1.fulfilment') }}</p>
            <h2 class="section-title">{{ __('lang_v1.shipping') }}</h2>
            <p class="section-desc">{{ __('lang_v1.where_the_goods_went') }}</p>
        </div>
    </div>

    @if ($canShip)
        <form method="POST" action="{{ route('sells.updateShipping', $document->id) }}" class="block">
            @csrf
            <x-panel>
                <div class="form-grid-3">
                    <div class="field">
                        <label for="shipping_status" class="label label-required">
                            {{ __('lang_v1.shipping_status') }}
                        </label>
                        {{-- No empty option, because updateShipping() requires one:
                             offering a blank choice would offer a rejection. --}}
                        <select id="shipping_status" name="shipping_status" class="select" required>
                            @foreach ($shippingStatuses as $value => $name)
                                <option value="{{ $value }}" @selected($document->shipping_status === $value)>
                                    {{ $name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="hint">{{ __('lang_v1.shipping_status_hint') }}</p>
                    </div>

                    <div class="field">
                        <label for="delivery_person" class="label">{{ __('lang_v1.delivery_person') }}</label>
                        <select id="delivery_person" name="delivery_person" class="select">
                            @foreach ($deliveryPeople as $id => $name)
                                <option value="{{ $id }}" @selected($document->delivery_person == $id)>
                                    {{ $name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="delivery_date" class="label">{{ __('lang_v1.delivery_date') }}</label>
                        <input type="date" id="delivery_date" name="delivery_date" class="input"
                               value="{{ $document->delivery_date?->toDateString() }}">
                    </div>

                    <div class="field">
                        <label for="delivered_to" class="label">{{ __('lang_v1.delivered_to') }}</label>
                        <input id="delivered_to" name="delivered_to" class="input"
                               value="{{ $document->delivered_to }}">
                    </div>

                    <div class="field">
                        <label for="shipping_details" class="label">{{ __('lang_v1.shipping_details') }}</label>
                        <input id="shipping_details" name="shipping_details" class="input"
                               value="{{ $document->shipping_details }}">
                    </div>

                    <div class="field">
                        <label for="shipping_charges" class="label">{{ __('lang_v1.shipping_charges') }}</label>
                        <input id="shipping_charges" name="shipping_charges" class="input-numeric"
                               inputmode="decimal" value="{{ $document->shipping_charges }}">
                    </div>

                    <div class="field sm:col-span-2 lg:col-span-3">
                        <label for="shipping_address" class="label">{{ __('lang_v1.shipping_address') }}</label>
                        <textarea id="shipping_address" name="shipping_address" rows="2"
                                  class="textarea">{{ $document->shipping_address }}</textarea>
                    </div>
                </div>

                <x-slot:footer>
                    <button type="submit" class="btn-primary">
                        <x-nav-icon name="save"/>
                        {{ __('lang_v1.update_shipping') }}
                    </button>
                </x-slot:footer>
            </x-panel>
        </form>
    @else
        <x-panel>
            @if ($hasShipping)
                {{-- Read-only for anyone without the shipping permission: the delivery
                     is still part of the record they are looking at. --}}
                <div class="attr-grid sm:grid-cols-3">
                    <div>
                        <dt class="attr-key">{{ __('lang_v1.shipping_status') }}</dt>
                        <dd class="attr-value">
                            @if ($document->shipping_status)
                                @transaction_status($document->shipping_status)
                            @else
                                {{ or_dash(null) }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="attr-key">{{ __('lang_v1.delivered_to') }}</dt>
                        <dd class="attr-value">{{ or_dash($document->delivered_to) }}</dd>
                    </div>
                    <div>
                        <dt class="attr-key">{{ __('lang_v1.delivery_date') }}</dt>
                        <dd class="attr-value">{{ or_dash(format_date($document->delivery_date)) }}</dd>
                    </div>
                    <div class="sm:col-span-3">
                        <dt class="attr-key">{{ __('lang_v1.shipping_address') }}</dt>
                        <dd class="attr-value">{{ or_dash($document->shipping_address) }}</dd>
                    </div>
                </div>
            @else
                <x-empty-state icon="truck" :title="__('lang_v1.no_shipments_hint')" compact/>
            @endif
        </x-panel>
    @endif
@endunless
@endsection
