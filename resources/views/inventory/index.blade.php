@extends('layouts.app')
@section('title', __('lang_v1.stock_counts'))
@section('page_title', __('lang_v1.stock_counts'))

@section('content')

@php
    $isFiltered = collect(['search', 'branch_id', 'status'])
        ->contains(fn ($key) => request()->filled($key));

    $canAdd = auth()->user()->can('inventorymanagement.create');
    $canEdit = auth()->user()->can('inventorymanagement.update');
    $canDelete = auth()->user()->can('inventorymanagement.delete');

    $columnCount = 5 + (int) ($canEdit || $canDelete);
@endphp

<x-page-head :subtitle="__('lang_v1.stock_counts_subtitle')">
    @if ($canAdd)
        <a href="{{ route('inventory.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.add_stock_count') }}
        </a>
    @endif
</x-page-head>

{{-- Open counts lead, because an open count is work in progress and a closed one
     is history. A shop with three counts left open across two branches has a
     problem the total count would never show. --}}
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-3">
        <x-stat :label="__('lang_v1.open_counts')"
                :value="format_quantity($totals['open'])"
                icon="clipboard"
                :hint="__('lang_v1.not_posted')"/>

        <x-stat :label="__('lang_v1.closed_counts')"
                :value="format_quantity($totals['closed'])"
                icon="check-circle"/>

        <x-stat :label="__('lang_v1.total_counts')"
                :value="format_quantity($totals['total'])"
                icon="layers"/>
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
                       placeholder="{{ __('lang_v1.count_name') }}">
            </div>
        </div>

        <div class="field">
            <label for="branch_id" class="label">{{ __('lang_v1.business_location') }}</label>
            <select id="branch_id" name="branch_id" class="select">
                @foreach ($locations as $id => $name)
                    <option value="{{ $id }}" @selected(request('branch_id') == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="status" class="label">{{ __('lang_v1.status') }}</label>
            <select id="status" name="status" class="select">
                @foreach ($statuses as $value => $name)
                    <option value="{{ $value }}" @selected(request('status') === (string) $value)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('inventory.index') }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.created_on') }}</th>
                <th>{{ __('lang_v1.count_name') }}</th>
                <th>{{ __('lang_v1.business_location') }}</th>
                <th class="th-numeric">{{ __('lang_v1.counted_items') }}</th>
                <th>{{ __('lang_v1.status') }}</th>
                @if ($canEdit || $canDelete)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $count)
                <tr>
                    <td class="whitespace-nowrap">@format_date($count->created_at)</td>

                    <td>
                        <a href="{{ route('inventory.show', $count->id) }}" class="cell-link">
                            {{ $count->name }}
                        </a>
                        @if ($count->end_date)
                            <span class="cell-meta">
                                {{ __('lang_v1.count_end_date') }}: <span class="force-ltr">@format_date($count->end_date)</span>
                            </span>
                        @endif
                    </td>

                    <td>{{ or_dash($count->branch->name ?? null) }}</td>

                    {{-- Counted and posted are two different facts, and on an open
                         count the second is always zero — so the posted figure is
                         a qualifier under the number rather than a column that
                         would be blank on every open row. --}}
                    <td class="cell-numeric">
                        @format_quantity($count->lines_count)
                        @if ($count->processed_lines_count > 0)
                            <span class="cell-meta">
                                {{ __('lang_v1.lines_posted', ['count' => format_quantity($count->processed_lines_count)]) }}
                            </span>
                        @endif
                    </td>

                    <td>
                        @if ($count->status)
                            <span class="badge-success">{{ __('lang_v1.closed') }}</span>
                        @else
                            <span class="badge-warning">{{ __('lang_v1.open') }}</span>
                        @endif
                    </td>

                    @if ($canEdit || $canDelete)
                        <td>
                            <div class="cell-actions">
                                @if ($canEdit && ! $count->status)
                                    <a href="{{ route('inventory.edit', $count->id) }}"
                                       class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                       aria-label="{{ __('lang_v1.edit') }}">
                                        <x-nav-icon name="edit" :size="4"/>
                                    </a>
                                @endif

                                {{-- Hidden rather than shown-and-refused once
                                     anything has posted: the service will reject
                                     it, and a button whose only outcome is an
                                     error message is a lie about what is possible. --}}
                                @if ($canDelete && $count->processed_lines_count === 0)
                                    <form method="POST" action="{{ route('inventory.destroy', $count->id) }}"
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
                               :icon="$isFiltered ? 'search' : 'clipboard'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.no_counts_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('lang_v1.no_counts_yet_desc')">
                    @if ($isFiltered)
                        <a href="{{ route('inventory.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canAdd)
                        <a href="{{ route('inventory.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.add_stock_count') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $records->links() }}
</div>
@endsection
