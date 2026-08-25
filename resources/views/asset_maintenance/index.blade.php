@extends('layouts.app')
@section('title', __('assetmanagement.maintenance'))
@section('page_title', __('assetmanagement.maintenance'))

@section('content')

@php
    $isFiltered = collect(['search', 'status', 'priority', 'asset_id', 'assigned_to'])
        ->contains(fn ($key) => request()->filled($key));

    $canAdd = auth()->user()->can('asset.create');
    $canEdit = auth()->user()->can('asset.update');
    $canDelete = auth()->user()->can('asset.delete');

    $columnCount = 5 + (int) ($canEdit || $canDelete);
@endphp

<x-page-head :subtitle="__('assetmanagement.maintenance_subtitle')">
    @if ($canAdd)
        <a href="{{ route('asset-maintenance.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('assetmanagement.raise_job') }}
        </a>
    @endif
</x-page-head>

{{-- Scheduled and in-progress lead, because those are the two states that mean
     somebody is waiting. Completed is history and sits last. --}}
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('assetmanagement.scheduled')"
                :value="format_quantity($totals['scheduled'])"
                icon="calendar"
                :tone="$totals['scheduled'] > 0 ? 'warning' : null"/>

        <x-stat :label="__('assetmanagement.in_progress')"
                :value="format_quantity($totals['in_progress'])"
                icon="cog"/>

        <x-stat :label="__('assetmanagement.completed')"
                :value="format_quantity($totals['completed'])"
                icon="check-circle"/>

        <x-stat :label="__('assetmanagement.total_jobs')"
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
                       placeholder="{{ __('assetmanagement.search_jobs_placeholder') }}">
            </div>
        </div>

        <div class="field">
            <label for="asset_id" class="label">{{ __('assetmanagement.asset') }}</label>
            <select id="asset_id" name="asset_id" class="select">
                @foreach ($assets as $id => $label)
                    <option value="{{ $id }}" @selected(request('asset_id') == $id)>{{ $label }}</option>
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

        <div class="field">
            <label for="priority" class="label">{{ __('lang_v1.priority') }}</label>
            <select id="priority" name="priority" class="select">
                @foreach ($priorities as $value => $name)
                    <option value="{{ $value }}" @selected(request('priority') === (string) $value)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        {{-- Absent for a technician: every row on their list is already theirs, so
             the control could only ever narrow the list to nothing. --}}
        @if ($showUserFilter)
            <div class="field">
                <label for="assigned_to" class="label">{{ __('assetmanagement.assigned_to') }}</label>
                <select id="assigned_to" name="assigned_to" class="select">
                    @foreach ($users as $id => $name)
                        <option value="{{ $id }}" @selected(request('assigned_to') == $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('asset-maintenance.index') }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.reference_no') }}</th>
                <th>{{ __('assetmanagement.asset') }}</th>
                <th>{{ __('assetmanagement.assigned_to') }}</th>
                <th>{{ __('lang_v1.priority') }}</th>
                <th>{{ __('lang_v1.status') }}</th>
                @if ($canEdit || $canDelete)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $job)
                @php
                    $isClosed = in_array($job->status, ['completed', 'cancelled'], true);
                @endphp
                <tr>
                    <td class="whitespace-nowrap">
                        @if ($canEdit)
                            <a href="{{ route('asset-maintenance.edit', $job->id) }}" class="cell-link force-ltr">
                                {{ $job->maitenance_id ?: '#'.$job->id }}
                            </a>
                        @else
                            <span class="cell-primary force-ltr">{{ $job->maitenance_id ?: '#'.$job->id }}</span>
                        @endif
                        <span class="cell-meta force-ltr">@format_date($job->created_at)</span>
                    </td>

                    <td>
                        @can('asset.view')
                            @if ($job->asset)
                                <a href="{{ route('assets.show', $job->asset_id) }}" class="cell-link">
                                    {{ $job->asset->name }}
                                </a>
                                <span class="cell-meta force-ltr">{{ $job->asset->asset_code }}</span>
                            @else
                                {{ or_dash(null) }}
                            @endif
                        @else
                            {{ or_dash($job->asset->name ?? null) }}
                        @endcan
                        @if ($job->details)
                            <span class="cell-meta">{{ \Illuminate\Support\Str::limit($job->details, 60) }}</span>
                        @endif
                    </td>

                    <td>
                        {{ or_dash($job->assignedTo->user_full_name ?? null) }}
                        @if ($job->createdBy)
                            <span class="cell-meta">
                                {{ __('assetmanagement.raised_by', ['name' => $job->createdBy->user_full_name]) }}
                            </span>
                        @endif
                    </td>

                    <td>
                        @php
                            $priorityBadge = match ($job->priority) {
                                'urgent' => 'badge-danger',
                                'high' => 'badge-warning',
                                'low' => 'badge-muted',
                                default => 'badge-info',
                            };
                        @endphp
                        @if ($job->priority)
                            <span class="{{ $priorityBadge }}">
                                {{ $priorities[$job->priority] ?? $job->priority }}
                            </span>
                        @else
                            {{ or_dash(null) }}
                        @endif
                    </td>

                    <td>
                        @if ($job->status)
                            <span @class([
                                'badge-success' => $job->status === 'completed',
                                'badge-muted' => $job->status === 'cancelled',
                                'badge-info' => $job->status === 'in_progress',
                                'badge-warning' => $job->status === 'scheduled',
                            ])>{{ $statuses[$job->status] ?? $job->status }}</span>
                        @else
                            {{ or_dash(null) }}
                        @endif
                    </td>

                    @if ($canEdit || $canDelete)
                        <td>
                            <div class="cell-actions">
                                @if ($canEdit)
                                    <a href="{{ route('asset-maintenance.edit', $job->id) }}"
                                       class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                       aria-label="{{ __('lang_v1.edit') }}">
                                        <x-nav-icon name="edit" :size="4"/>
                                    </a>
                                @endif

                                @if ($canDelete)
                                    <form method="POST" action="{{ route('asset-maintenance.destroy', $job->id) }}"
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
                               :icon="$isFiltered ? 'search' : 'cog'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('assetmanagement.no_jobs_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('assetmanagement.no_jobs_yet_desc')">
                    @if ($isFiltered)
                        <a href="{{ route('asset-maintenance.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canAdd)
                        <a href="{{ route('asset-maintenance.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('assetmanagement.raise_job') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $records->links() }}
</div>
@endsection
