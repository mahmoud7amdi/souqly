@extends('layouts.app')
@section('title', __('lang_v1.stock_adjustments'))
@section('page_title', __('lang_v1.stock_adjustments'))

@section('content')

@php
    $isFiltered = collect(['search', 'location_id', 'adjustment_type', 'start_date', 'end_date'])
        ->contains(fn ($key) => request()->filled($key));

    $canAdd = auth()->user()->can('stock_adjustment.create');
    $canEdit = auth()->user()->can('stock_adjustment.update');
    $canDelete = auth()->user()->can('stock_adjustment.delete');

    $columnCount = 7 + (int) ($canEdit || $canDelete);
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $records->total(), ['count' => $records->total()])">
    @if ($canAdd)
        <a href="{{ route('stock-adjustments.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.add_stock_adjustment') }}
        </a>
    @endif
</x-page-head>

{{-- Loss and recovery are shown side by side rather than netted: a month where
     breakages doubled but insurance paid for them is a different month from one
     where nothing broke, and one net figure cannot tell them apart. The abnormal
     total is the one worth a manager's attention, so it is toned when non-zero. --}}
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('lang_v1.total_loss')"
                :value="format_currency($totals['loss'])"
                icon="adjust"
                :hint="__('lang_v1.valued_at_cost')"/>

        <x-stat :label="__('lang_v1.amount_recovered')"
                :value="format_currency($totals['recovered'])"
                icon="undo"/>

        <x-stat :label="__('lang_v1.net_loss')"
                :value="format_currency($totals['net'])"
                icon="calculator"/>

        <x-stat :label="__('lang_v1.abnormal_loss')"
                :value="format_currency($totals['abnormal'])"
                icon="alert"
                :tone="$totals['abnormal'] > 0 ? 'warning' : null"/>
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
            <label for="adjustment_type" class="label">{{ __('lang_v1.adjustment_type') }}</label>
            <select id="adjustment_type" name="adjustment_type" class="select">
                @foreach ($types as $value => $name)
                    <option value="{{ $value }}" @selected(request('adjustment_type') === (string) $value)>
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
                <a href="{{ route('stock-adjustments.index') }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.business_location') }}</th>
                <th>{{ __('lang_v1.adjustment_type') }}</th>
                <th>{{ __('lang_v1.reason') }}</th>
                <th class="th-numeric">{{ __('lang_v1.total_loss') }}</th>
                <th class="th-numeric">{{ __('lang_v1.amount_recovered') }}</th>
                @if ($canEdit || $canDelete)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                <tr>
                    <td class="whitespace-nowrap">@format_date($record->transaction_date)</td>

                    <td>
                        <a href="{{ route('stock-adjustments.show', $record->id) }}" class="cell-link force-ltr">
                            {{ or_dash($record->ref_no) }}
                        </a>
                        @if ($record->created_user)
                            <span class="cell-meta">{{ $record->created_user->user_full_name }}</span>
                        @endif
                    </td>

                    <td>{{ or_dash($record->location->name ?? null) }}</td>

                    {{-- Abnormal is the exception and reads as one; normal shrinkage
                         gets no badge, because badging both would make neither
                         stand out. --}}
                    <td>
                        @if ($record->adjustment_type === 'abnormal')
                            <span class="badge-warning">{{ __('lang_v1.abnormal') }}</span>
                        @else
                            <span class="text-slate-500">{{ __('lang_v1.normal') }}</span>
                        @endif
                    </td>

                    <td class="max-w-xs truncate">{{ or_dash($record->additional_notes) }}</td>

                    <td class="cell-numeric">@format_currency($record->final_total)</td>

                    <td class="cell-numeric">
                        @if ((float) $record->total_amount_recovered > 0)
                            <span class="text-emerald-600">@format_currency($record->total_amount_recovered)</span>
                        @else
                            —
                        @endif
                    </td>

                    @if ($canEdit || $canDelete)
                        <td>
                            <div class="cell-actions">
                                @if ($canEdit)
                                    <a href="{{ route('stock-adjustments.edit', $record->id) }}"
                                       class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                       aria-label="{{ __('lang_v1.edit') }}">
                                        <x-nav-icon name="edit" :size="4"/>
                                    </a>
                                @endif

                                @if ($canDelete)
                                    <form method="POST" action="{{ route('stock-adjustments.destroy', $record->id) }}"
                                          data-confirm="{{ __('lang_v1.confirm_delete_adjustment') }}">
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
                               :icon="$isFiltered ? 'search' : 'adjust'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('lang_v1.adjustments_record_what_was_lost')">
                    @if ($isFiltered)
                        <a href="{{ route('stock-adjustments.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canAdd)
                        <a href="{{ route('stock-adjustments.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.add_stock_adjustment') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $records->links() }}
</div>
@endsection
