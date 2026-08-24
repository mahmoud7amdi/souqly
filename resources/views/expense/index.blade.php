@extends('layouts.app')
@section('title', __('lang_v1.expenses'))
@section('page_title', __('lang_v1.expenses'))

@section('content')

@php
    $isFiltered = collect(['search', 'location_id', 'expense_category_id', 'expense_for',
                           'payment_status', 'start_date', 'end_date'])
        ->contains(fn ($key) => request()->filled($key));

    $canAdd = auth()->user()->can('expense.add');
    $canEdit = auth()->user()->can('expense.edit');
    $canDelete = auth()->user()->can('expense.delete');
    $canPay = auth()->user()->can('expense.add') && Route::has('payments.create');

    $columnCount = 7 + (int) ($canEdit || $canDelete || $canPay);
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $records->total(), ['count' => $records->total()])">
    @if ($canAdd)
        <a href="{{ route('expenses.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.add_expense') }}
        </a>
    @endif
</x-page-head>

{{-- Net cost first, because that is the question this screen answers. The refund
     figure sits beside it rather than being folded away invisibly: a manager who
     sees a total drop wants to know whether spending fell or money came back. --}}
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('lang_v1.net_expense')"
                :value="format_currency($totals['total'])"
                icon="receipt"
                :hint="__('lang_v1.after_refunds')"/>

        <x-stat :label="__('lang_v1.refunds')"
                :value="format_currency($totals['refund'])"
                icon="undo"/>

        <x-stat :label="__('lang_v1.paid')"
                :value="format_currency($totals['paid'])"
                icon="check-circle"/>

        <x-stat :label="__('lang_v1.due')"
                :value="format_currency($totals['due'])"
                icon="clock"
                :tone="$totals['due'] > 0 ? 'danger' : null"/>
    </div>
</div>

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field">
            <label for="search" class="label">{{ __('lang_v1.search') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search"
                       placeholder="{{ __('lang_v1.ref_no_or_note') }}">
            </div>
        </div>

        <div class="field">
            <label for="location_id" class="label">{{ __('lang_v1.business_location') }}</label>
            <select id="location_id" name="location_id" class="select">
                @foreach ($locations as $id => $name)
                    <option value="{{ $id }}" @selected(request('location_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="expense_category_id" class="label">{{ __('lang_v1.expense_category') }}</label>
            {{-- Parents and children in one list, and the filter matches either
                 column — so picking a parent returns its sub-categories too,
                 which is what a person means by "show me rent". --}}
            <select id="expense_category_id" name="expense_category_id" class="select">
                @foreach ($categories as $id => $name)
                    <option value="{{ $id }}" @selected(request('expense_category_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="expense_for" class="label">{{ __('lang_v1.expense_for') }}</label>
            <select id="expense_for" name="expense_for" class="select">
                @foreach ($users as $id => $name)
                    <option value="{{ $id }}" @selected(request('expense_for') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="payment_status" class="label">{{ __('lang_v1.payment_status') }}</label>
            <select id="payment_status" name="payment_status" class="select">
                @foreach ($statuses as $value => $name)
                    <option value="{{ $value }}" @selected(request('payment_status') === (string) $value)>{{ $name }}</option>
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
                <a href="{{ route('expenses.index') }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.expense_category') }}</th>
                <th>{{ __('lang_v1.business_location') }}</th>
                <th>{{ __('lang_v1.expense_for') }}</th>
                <th>{{ __('lang_v1.payment_status') }}</th>
                <th class="th-numeric">{{ __('lang_v1.amount') }}</th>
                @if ($canEdit || $canDelete || $canPay)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $expense)
                @php
                    $isRefund = $expense->type === \App\Support\TransactionTypes::EXPENSE_REFUND;
                @endphp
                <tr>
                    <td class="whitespace-nowrap">@format_date($expense->transaction_date)</td>

                    <td>
                        <a href="{{ route('expenses.show', $expense->id) }}" class="cell-link force-ltr">
                            {{ or_dash($expense->ref_no) }}
                        </a>
                        {{-- A refund is the same record type pointed the other way,
                             so it is flagged on the row rather than given a column
                             that would be empty on every other line. --}}
                        @if ($isRefund)
                            <span class="badge-warning">{{ __('lang_v1.refund') }}</span>
                        @endif
                        @if ($expense->is_recurring)
                            <span class="cell-meta">{{ __('lang_v1.recurring') }}</span>
                        @elseif ($expense->recur_parent_id)
                            <span class="cell-meta">{{ __('lang_v1.generated_occurrence') }}</span>
                        @endif
                    </td>

                    {{-- Category and sub-category in one cell: the sub is a
                         qualifier of the parent, not an independent fact. --}}
                    <td>
                        <span class="cell-primary">{{ or_dash($expense->expense_category->name ?? null) }}</span>
                        @if ($expense->expense_sub_category)
                            <span class="cell-meta">{{ $expense->expense_sub_category->name }}</span>
                        @endif
                    </td>

                    <td>{{ or_dash($expense->location->name ?? null) }}</td>

                    <td>
                        <span class="cell-primary">{{ or_dash($expense->transaction_for->user_full_name ?? null) }}</span>
                        @if ($expense->contact)
                            <span class="cell-meta">{{ $expense->contact->full_name_with_business }}</span>
                        @endif
                    </td>

                    <td>@payment_status($expense->payment_status)</td>

                    <td @class(['cell-numeric', 'text-amber-700' => $isRefund])>
                        @if ($isRefund)&minus;@endif@format_currency($expense->final_total)
                    </td>

                    @if ($canEdit || $canDelete || $canPay)
                        <td>
                            <div class="cell-actions">
                                @if ($canPay && $expense->payment_status !== \App\Support\TransactionTypes::PAID)
                                    <a href="{{ route('payments.create', ['transaction_id' => $expense->id]) }}"
                                       class="btn-icon" title="{{ __('lang_v1.add_payment') }}"
                                       aria-label="{{ __('lang_v1.add_payment') }}">
                                        <x-nav-icon name="cash" :size="4"/>
                                    </a>
                                @endif

                                @if ($canEdit)
                                    <a href="{{ route('expenses.edit', $expense->id) }}"
                                       class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                       aria-label="{{ __('lang_v1.edit') }}">
                                        <x-nav-icon name="edit" :size="4"/>
                                    </a>
                                @endif

                                @if ($canDelete)
                                    <form method="POST" action="{{ route('expenses.destroy', $expense->id) }}"
                                          data-confirm="{{ __('lang_v1.confirm_delete') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-icon-danger"
                                                title="{{ __('lang_v1.delete') }}"
                                                aria-label="{{ __('lang_v1.delete') }}">
                                            <x-nav-icon name="trash" :size="4"/>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <x-table-empty :columns="$columnCount"
                               :icon="$isFiltered ? 'search' : 'receipt'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('lang_v1.expenses_track_what_you_spend')">
                    @if ($isFiltered)
                        <a href="{{ route('expenses.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canAdd)
                        <a href="{{ route('expenses.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.add_expense') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $records->links() }}
</div>
@endsection
