@extends('layouts.app')
@section('title', __('lang_v1.stock_transfers'))
@section('page_title', __('lang_v1.stock_transfers'))

@section('content')

@php
    $isFiltered = collect(['search', 'location_id', 'status', 'start_date', 'end_date'])
        ->contains(fn ($key) => request()->filled($key));

    $canAdd = auth()->user()->can('stock_transfer.create');
    $canReceive = auth()->user()->can('stock_transfer.update');
    $canDelete = auth()->user()->can('stock_transfer.delete');

    $columnCount = 6 + (int) ($canReceive || $canDelete);
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $records->total(), ['count' => $records->total()])">
    @if ($canAdd)
        <a href="{{ route('stock-transfers.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.add_stock_transfer') }}
        </a>
    @endif
</x-page-head>

{{-- "In transit" is the figure that needs acting on, not just reading: every one
     of those is stock that has left a shop and has not been confirmed anywhere
     yet. It is toned when non-zero so the number itself is the reminder. --}}
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('lang_v1.transfers')"
                :value="number_format($totals['count'])"
                icon="transfer"/>

        <x-stat :label="__('lang_v1.total_value')"
                :value="format_currency($totals['value'])"
                icon="coins"
                :hint="__('lang_v1.valued_at_cost')"/>

        <x-stat :label="__('lang_v1.shipping_charges')"
                :value="format_currency($totals['shipping'])"
                icon="truck"/>

        <x-stat :label="__('lang_v1.in_transit')"
                :value="number_format($totals['in_transit'])"
                icon="clock"
                :tone="$totals['in_transit'] > 0 ? 'warning' : null"
                :hint="__('lang_v1.awaiting_receipt')"/>
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

        {{-- One location field, matching either leg: "which shop was it to or
             from" is a single question to whoever is looking. --}}
        <div class="field">
            <label for="location_id" class="label">{{ __('lang_v1.business_location') }}</label>
            <select id="location_id" name="location_id" class="select">
                @foreach ($locations as $id => $name)
                    <option value="{{ $id }}" @selected(request('location_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="status" class="label">{{ __('lang_v1.status') }}</label>
            <select id="status" name="status" class="select">
                @foreach ($statuses as $value => $name)
                    <option value="{{ $value }}" @selected(request('status') === (string) $value)>
                        {{ $name }}
                    </option>
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
                <a href="{{ route('stock-transfers.index') }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.route') }}</th>
                <th>{{ __('lang_v1.status') }}</th>
                <th>{{ __('lang_v1.shipping_details') }}</th>
                <th class="th-numeric">{{ __('lang_v1.total') }}</th>
                @if ($canReceive || $canDelete)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                @php $inTransit = $record->status === 'in_transit'; @endphp
                <tr>
                    <td class="whitespace-nowrap">@format_date($record->transaction_date)</td>

                    <td>
                        <a href="{{ route('stock-transfers.show', $record->id) }}" class="cell-link force-ltr">
                            {{ or_dash($record->ref_no) }}
                        </a>
                        @if ($record->created_user)
                            <span class="cell-meta">{{ $record->created_user->user_full_name }}</span>
                        @endif
                    </td>

                    {{-- Source → destination in one cell. The arrow is logical, so
                         it points the reading direction in both languages, and the
                         pair is what identifies the transfer at a glance. --}}
                    <td>
                        <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                            <span class="cell-primary">{{ or_dash($record->location->name ?? null) }}</span>
                            <span class="text-slate-400"><x-nav-icon name="arrow-forward" :size="4"/></span>
                            <span class="cell-primary">
                                {{ or_dash($record->transfer_child->location->name ?? null) }}
                            </span>
                        </span>
                    </td>

                    <td><x-transaction-status :status="$record->status"/></td>

                    <td class="max-w-xs truncate">{{ or_dash($record->shipping_details) }}</td>

                    <td class="cell-numeric">@format_currency($record->final_total)</td>

                    @if ($canReceive || $canDelete)
                        <td>
                            <div class="cell-actions">
                                {{-- Receive appears only on the rows it can act on.
                                     A disabled button on every completed row would
                                     be noise; the absence is the answer. --}}
                                @if ($canReceive && $inTransit)
                                    <form method="POST"
                                          action="{{ route('stock-transfers.updateStatus', $record->id) }}"
                                          data-confirm="{{ __('lang_v1.confirm_receive_transfer') }}">
                                        @csrf
                                        <button type="submit" class="btn-icon"
                                                title="{{ __('lang_v1.mark_received') }}"
                                                aria-label="{{ __('lang_v1.mark_received') }}">
                                            <x-nav-icon name="check-circle" :size="4"/>
                                        </button>
                                    </form>
                                @endif

                                @if ($canDelete)
                                    <form method="POST" action="{{ route('stock-transfers.destroy', $record->id) }}"
                                          data-confirm="{{ __('lang_v1.confirm_delete_transfer') }}">
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
                               :icon="$isFiltered ? 'search' : 'transfer'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('lang_v1.transfers_move_stock_between_shops')">
                    @if ($isFiltered)
                        <a href="{{ route('stock-transfers.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canAdd)
                        <a href="{{ route('stock-transfers.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.add_stock_transfer') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $records->links() }}
</div>
@endsection
