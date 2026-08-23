@extends('layouts.app')
@section('title', __('lang_v1.expense'))
@section('page_title', __('lang_v1.expense'))

@section('content')

@php
    $isRefund = $expense->type === \App\Support\TransactionTypes::EXPENSE_REFUND;

    $canEdit = auth()->user()->can('expense.edit');
    $canDelete = auth()->user()->can('expense.delete');
    $canPay = auth()->user()->can('expense.add')
        && Route::has('payments.create')
        && $due > 0.0001;

    $payments = $expense->payment_lines;
@endphp

<x-page-head :back="route('expenses.index')" :backLabel="__('lang_v1.expenses')">
    <x-slot:subtitle>
        <span class="force-ltr">{{ or_dash($expense->ref_no) }}</span>
        <span class="text-slate-300">&middot;</span>
        @format_date($expense->transaction_date)
        @if ($expense->location)
            <span class="text-slate-300">&middot;</span>
            {{ $expense->location->name }}
        @endif

        @payment_status($expense->payment_status)

        @if ($isRefund)
            <span class="badge-warning">{{ __('lang_v1.refund') }}</span>
        @endif
        @if ($expense->is_recurring)
            <span class="badge-brand">{{ __('lang_v1.recurring') }}</span>
        @endif
    </x-slot:subtitle>

    @if ($canPay)
        <a href="{{ route('payments.create', ['transaction_id' => $expense->id]) }}" class="btn-accent">
            <x-nav-icon name="cash" :size="4"/>
            {{ __('lang_v1.add_payment') }}
        </a>
    @endif

    @if ($canEdit)
        <a href="{{ route('expenses.edit', $expense->id) }}" class="btn-secondary">
            <x-nav-icon name="edit" :size="4"/>
            {{ __('lang_v1.edit') }}
        </a>
    @endif

    @if ($canDelete)
        <form method="POST" action="{{ route('expenses.destroy', $expense->id) }}"
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

        {{-- ============ What it was ============ --}}
        <x-panel :title="__('lang_v1.expense_details')" icon="receipt">
            <x-attr-list :columns="2" :items="[
                'lang_v1.expense_category' => $expense->expense_category->name ?? null,
                'lang_v1.sub_category' => $expense->expense_sub_category->name ?? null,
                'lang_v1.business_location' => $expense->location->name ?? null,
                'lang_v1.expense_for' => $expense->transaction_for->user_full_name ?? null,
                'lang_v1.supplier' => $expense->contact->full_name_with_business ?? null,
                'lang_v1.subscription_no' => $expense->subscription_no,
            ]"/>

            @if ($expense->additional_notes)
                <div class="mt-5">
                    <p class="section-label">{{ __('lang_v1.note') }}</p>
                    <p class="whitespace-pre-line text-sm text-slate-700">{{ $expense->additional_notes }}</p>
                </div>
            @endif
        </x-panel>

        {{-- ============ Payments ============
             The rows themselves, not just a total: "paid 400 of 1,000" is a
             summary, and the question people actually bring to this screen is
             which payment, when, and out of which account. --}}
        <x-panel :title="__('lang_v1.payments')" icon="cash" :count="$payments->count()" flush>
            @if ($payments->isEmpty())
                <div class="p-5">
                    <x-empty-state icon="cash" compact
                                   :title="__('lang_v1.not_paid_yet')"
                                   :text="__('lang_v1.no_payments_against_this_expense')"/>
                </div>
            @else
                <div class="table-wrap table-flush">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('lang_v1.date') }}</th>
                                <th>{{ __('lang_v1.reference_no') }}</th>
                                <th>{{ __('lang_v1.payment_method') }}</th>
                                <th>{{ __('lang_v1.account') }}</th>
                                <th>{{ __('lang_v1.added_by') }}</th>
                                <th class="th-numeric">{{ __('lang_v1.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payments as $payment)
                                <tr>
                                    <td class="whitespace-nowrap">@format_date($payment->paid_on)</td>
                                    <td>
                                        @if (Route::has('payments.show'))
                                            <a href="{{ route('payments.show', $payment->id) }}"
                                               class="cell-link force-ltr">{{ or_dash($payment->payment_ref_no) }}</a>
                                        @else
                                            <span class="force-ltr">{{ or_dash($payment->payment_ref_no) }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $payment->method_label }}</td>
                                    <td>{{ or_dash($payment->payment_account->name ?? null) }}</td>
                                    <td>{{ or_dash($payment->created_user->user_full_name ?? null) }}</td>
                                    <td @class(['cell-numeric', 'text-rose-600' => $payment->is_return])>
                                        @if ($payment->is_return)&minus;@endif@format_currency($payment->amount)
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-panel>

        {{-- ============ Occurrences ============
             Only a recurring template has them. The template itself is not one of
             its own occurrences, so the count here is what has actually been
             generated — which is the number a person checks when they suspect the
             scheduler stopped running. --}}
        @if ($occurrences->isNotEmpty())
            <x-panel :title="__('lang_v1.generated_occurrences')" icon="refresh"
                     :count="$occurrences->count()"
                     :subtitle="__('lang_v1.created_from_this_template')" flush>
                <div class="table-wrap table-flush">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('lang_v1.date') }}</th>
                                <th>{{ __('lang_v1.reference_no') }}</th>
                                <th>{{ __('lang_v1.payment_status') }}</th>
                                <th class="th-numeric">{{ __('lang_v1.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($occurrences as $occurrence)
                                <tr>
                                    <td class="whitespace-nowrap">@format_date($occurrence->transaction_date)</td>
                                    <td>
                                        <a href="{{ route('expenses.show', $occurrence->id) }}"
                                           class="cell-link force-ltr">{{ or_dash($occurrence->ref_no) }}</a>
                                    </td>
                                    <td>@payment_status($occurrence->payment_status)</td>
                                    <td class="cell-numeric">@format_currency($occurrence->final_total)</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @endif
    </div>

    <div class="grid gap-6 self-start">
        {{-- ============ The money ============ --}}
        <x-panel :title="__('lang_v1.amount')" icon="calculator">
            <dl class="dl">
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.net_amount') }}</dt>
                    <dd class="dl-value">@format_currency($expense->total_before_tax)</dd>
                </div>
                <div class="dl-row">
                    <dt class="dl-key">
                        {{ __('lang_v1.tax') }}
                        @if ($expense->tax)
                            <span class="text-slate-400">({{ $expense->tax->name }})</span>
                        @endif
                    </dt>
                    <dd class="dl-value">@format_currency($expense->tax_amount)</dd>
                </div>
                <div class="dl-total">
                    <dt class="font-semibold text-slate-900">{{ __('lang_v1.total') }}</dt>
                    <dd class="dl-total-value">@format_currency($expense->final_total)</dd>
                </div>
            </dl>

            <dl class="dl mt-5">
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.paid') }}</dt>
                    <dd class="dl-value text-emerald-700">@format_currency($paid)</dd>
                </div>
                <div class="dl-row">
                    <dt class="dl-key">{{ __('lang_v1.due') }}</dt>
                    <dd @class(['dl-value', 'font-semibold text-rose-600' => $due > 0.0001])>
                        @format_currency($due)
                    </dd>
                </div>
            </dl>
        </x-panel>

        {{-- ============ The schedule ============ --}}
        @if ($expense->is_recurring)
            <x-panel :title="__('lang_v1.recurring')" icon="refresh">
                <x-attr-list :columns="1" :items="[
                    'lang_v1.every' => $expense->recur_interval.' '
                        .__('lang_v1.'.$expense->recur_interval_type),
                    'lang_v1.repetitions' => $expense->recur_repetitions ?: __('lang_v1.unlimited'),
                    'lang_v1.generated_so_far' => $occurrences->count(),
                ]"/>
            </x-panel>
        @elseif ($expense->recur_parent_id)
            <x-panel :title="__('lang_v1.recurring')" icon="refresh">
                <p class="text-sm text-slate-600">{{ __('lang_v1.generated_from_a_template') }}</p>
                <a href="{{ route('expenses.show', $expense->recur_parent_id) }}" class="link mt-2 inline-flex">
                    {{ __('lang_v1.view_the_template') }}
                </a>
            </x-panel>
        @endif

        <x-panel :title="__('lang_v1.record')" icon="info">
            <x-attr-list :columns="1" :items="[
                'lang_v1.added_by' => $expense->created_user->user_full_name ?? null,
                'lang_v1.recorded_at' => format_datetime($expense->created_at),
            ]"/>
        </x-panel>
    </div>
</div>
@endsection
