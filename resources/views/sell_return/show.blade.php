@extends('layouts.app')
@section('title', $return->invoice_no)
@section('page_title', __('lang_v1.sell_return').' — '.$return->invoice_no)

@section('content')

{{-- Same shape as a sale document: the sticky header names the type and number,
     so the head carries who, when, and the invoice this reverses. --}}
<x-page-head :back="route('sell-return.index')" :backLabel="__('lang_v1.sell_returns')">
    <x-slot:subtitle>
        <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="font-medium text-slate-700">
                {{ or_dash($return->contact->full_name_with_business ?? null) }}
            </span>
            <span class="text-slate-300">&middot;</span>
            <span class="force-ltr">@format_datetime($return->transaction_date)</span>
            <span class="text-slate-300">&middot;</span>
            <span>{{ or_dash($return->location->name ?? null) }}</span>

            @if ($return->return_parent_sell)
                <span class="text-slate-300">&middot;</span>
                <span>
                    {{ __('lang_v1.parent_sale') }}:
                    <a href="{{ route('sells.show', $return->return_parent_sell->id) }}"
                       class="link force-ltr">
                        {{ $return->return_parent_sell->invoice_no }}
                    </a>
                </span>
            @endif

            <span class="ms-1 inline-flex items-center">
                @payment_status($return->payment_status)
            </span>
        </span>
    </x-slot:subtitle>

    {{-- The credit-note renderer — see the note on `sell/show`. Same view as the
         invoice; the layout's credit-note heading and `credit_note_no` labels are
         what make it read as a return rather than a sale. --}}
    <a href="{{ route('print.invoice', $return->id) }}" class="btn-secondary">
        <x-nav-icon name="printer"/>
        {{ __('lang_v1.print') }}
    </a>
</x-page-head>

<div class="grid gap-6 lg:grid-cols-4">

    <x-panel :title="__('lang_v1.items')" icon="box" :count="$lines->count()" class="lg:col-span-3" flush>
        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.product') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.returned_quantity') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.unit_price') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.subtotal') }}</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- The quantities live on the sale's lines, not on the return: a
                         return has no lines of its own, which is why this table is
                         built from the parent sale filtered to what came back. --}}
                    @forelse ($lines as $line)
                        <tr>
                            <td>
                                <span class="cell-primary">{{ $line->variations->full_name }}</span>
                                <span class="cell-meta force-ltr">{{ $line->variations->sub_sku }}</span>
                            </td>
                            <td class="cell-numeric">@format_quantity($line->quantity_returned)</td>
                            <td class="cell-numeric">@format_currency($line->unit_price_inc_tax)</td>
                            <td class="cell-numeric">
                                @format_currency($line->quantity_returned * $line->unit_price_inc_tax)
                            </td>
                        </tr>
                    @empty
                        <x-table-empty :columns="4" icon="undo"/>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-panel>

    <div class="grid gap-6 self-start">

        <x-panel :title="__('lang_v1.summary')" icon="coins">
            {{-- No .dl-total here: that primitive draws a rule to close a breakdown,
                 and a return has nothing above its total to sum up. Weight alone
                 makes the figure the heading of the panel it sits in. --}}
            <dl class="dl">
                <div class="dl-row">
                    <dt class="font-semibold text-slate-900">{{ __('lang_v1.total') }}</dt>
                    <dd class="dl-total-value">@format_currency($return->final_total)</dd>
                </div>

                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.paid') }}</dt>
                    <dd class="dl-value text-emerald-600">@format_currency($paid)</dd>
                </div>

                {{-- Money still owed back to the customer, so it is toned only while
                     it is outstanding — a refunded return should read as closed. --}}
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.due') }}</dt>
                    <dd @class(['dl-value', 'font-semibold text-rose-600' => $due > 0])>
                        @format_currency($due)
                    </dd>
                </div>
            </dl>
        </x-panel>

        @if ($return->payment_lines->isNotEmpty())
            {{-- Refunds actually handed over. No account name: payment_account is not
                 eager loaded on this screen, and lazy loading is barred. --}}
            <x-panel :title="__('lang_v1.payments')" icon="cash"
                     :count="$return->payment_lines->count()">
                <dl class="dl">
                    @foreach ($return->payment_lines as $payment)
                        <div class="dl-row">
                            <dt class="min-w-0">
                                <span class="block text-slate-700">{{ __('lang_v1.'.$payment->method) }}</span>
                                <span class="cell-meta">@format_date($payment->paid_on)</span>
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
