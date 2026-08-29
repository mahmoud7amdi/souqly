@extends('layouts.app')
@section('title', __('accounting.cost_centers'))
@section('page_title', __('accounting.cost_centers'))

@section('content')

{{--
    Cost centres.

    No show screen, by design: a cost centre has no history of its own to display —
    what it carries is journal lines, and those are already readable at
    `accounting.journal.index` filtered by cost centre. So the entries column is a
    count that links there rather than to a page that would only rebuild the same
    filter. The controller registers no `show` route, and adding one to hold a table
    the journal already renders would be a second answer to one question.

    `is_active` is a badge beside the name rather than its own column, the same
    treatment archived accounts get in the chart: state is a property of the row, and
    a column of "Active / Active / Active" spends a column saying nothing.
--}}

@php
    $isFiltered = collect(['search', 'type'])->contains(fn ($key) => request()->filled($key));

    /* 7 columns, plus actions when the user may edit. Hard-coding 8 would leave the
       empty-state row short by one on a read-only role and pull the whole table
       out of line. */
    $columnCount = 7 + (int) $canEdit;
@endphp

<x-page-head :subtitle="__('accounting.cost_centers_subtitle')">
    @if ($canAdd)
        <a href="{{ route('accounting.cost-centers.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('accounting.add_cost_center') }}
        </a>
    @endif
</x-page-head>

<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-3">
        <x-stat :label="__('accounting.total_cost_centers')"
                :value="format_quantity($totals['total'])" icon="layers"/>

        <x-stat :label="__('accounting.active_cost_centers')"
                :value="format_quantity($totals['active'])" icon="check-circle"/>

        <x-stat :label="__('accounting.total_budget')"
                :value="format_currency($totals['budget'])" icon="coins"
                :hint="__('accounting.budget_details_hint')"/>
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
                       placeholder="{{ __('accounting.search_cost_centers_placeholder') }}">
            </div>
        </div>

        <div class="field">
            <label for="type" class="label">{{ __('accounting.cost_center_type') }}</label>
            <select id="type" name="type" class="select">
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected(request('type') === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('accounting.cost-centers.index') }}" class="btn-secondary">
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
                <th>{{ __('accounting.cost_center_code') }}</th>
                <th>{{ __('lang_v1.name') }}</th>
                <th>{{ __('accounting.cost_center_type') }}</th>
                <th>{{ __('accounting.manager') }}</th>
                <th>{{ __('lang_v1.business_location') }}</th>
                <th class="th-numeric">{{ __('accounting.budget_amount') }}</th>
                <th class="th-numeric">{{ __('accounting.entries') }}</th>
                @if ($canEdit)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $center)
                <tr>
                    <td class="cell-primary force-ltr whitespace-nowrap">{{ $center->code }}</td>

                    {{-- `parent`, `manager` and `location` are BelongsTo without
                         withDefault() on this model — unlike ChartOfAccount::parent()
                         — so all three need `?->`. --}}
                    <td>
                        <span class="cell-primary">{{ $center->name }}</span>
                        <span class="cell-meta">
                            @if ($center->parent_id)
                                <span class="icon-directional">&#8627;</span>
                                {{ $center->parent?->name }}
                            @endif
                            @if (! $center->is_active)
                                <span class="badge-muted">{{ __('accounting.account_is_inactive') }}</span>
                            @endif
                        </span>
                    </td>

                    <td>
                        <span class="badge-brand">
                            {{ __('accounting.type_'.$center->type) }}
                        </span>
                    </td>

                    <td>{{ or_dash($center->manager?->user_full_name) }}</td>

                    <td>{{ or_dash($center->location?->name) }}</td>

                    <td class="cell-numeric">
                        @if ((float) $center->budget_amount > 0)
                            @format_currency($center->budget_amount)
                            @if ($center->budget_period)
                                <span class="cell-meta">{{ __('accounting.'.$center->budget_period) }}</span>
                            @endif
                        @else
                            {{ or_dash(null) }}
                        @endif
                    </td>

                    {{-- Links into the journal filtered to this centre, which is the
                         screen that can actually answer "which entries". --}}
                    <td class="cell-numeric">
                        @if ($center->journal_entries_count > 0)
                            <a href="{{ route('accounting.journal.index', ['cost_center_id' => $center->id]) }}"
                               class="cell-link">
                                {{ format_quantity($center->journal_entries_count) }}
                            </a>
                        @else
                            {{ or_dash(null) }}
                        @endif
                    </td>

                    @if ($canEdit)
                        <td>
                            <div class="cell-actions">
                                <a href="{{ route('accounting.cost-centers.edit', $center->id) }}"
                                   class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                   aria-label="{{ __('lang_v1.edit') }}">
                                    <x-nav-icon name="edit" :size="4"/>
                                </a>

                                {{-- Hidden where it is certain to fail: the service
                                     refuses to delete a centre journal entries point
                                     at and says to deactivate it instead, and the
                                     listing already counts those entries — so that
                                     refusal can be kept off the screen entirely.

                                     It does not count children, and the service
                                     refuses on those too, so a parent centre still
                                     shows a button that comes back with
                                     `cost_center_has_children`. Loading a second
                                     count on every row to pre-empt the rarer of two
                                     refusals is not worth the query; the message
                                     names the reason and the fix. --}}
                                @if ($center->journal_entries_count === 0)
                                    <form method="POST"
                                          action="{{ route('accounting.cost-centers.destroy', $center->id) }}"
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
                               :icon="$isFiltered ? 'search' : 'layers'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('accounting.no_cost_centers_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('accounting.no_cost_centers_yet_desc')">
                    @if ($isFiltered)
                        <a href="{{ route('accounting.cost-centers.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canAdd)
                        <a href="{{ route('accounting.cost-centers.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('accounting.add_cost_center') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $records->links() }}
</div>
@endsection
