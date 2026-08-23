@extends('layouts.app')
@section('title', $label)
@section('page_title', $label)

@section('content')

@php
    /* Whether the list is empty because nothing exists yet or because a filter
       excluded everything — the two need different empty states, and telling
       them apart is the difference between "add your first brand" and "your
       search matched nothing". */
    $isFiltered = request()->filled('search');
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $records->total(), ['count' => $records->total()])">
    @if ($canCreate)
        <a href="{{ route($routePrefix.'.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.add') }}
        </a>
    @endif
</x-page-head>

{{-- Filters sit in a sunken strip, not a white card: they are secondary to the
     data and must not compete with the table for attention. --}}
<form method="GET" class="filter-bar">
    <div class="flex flex-wrap items-end gap-3">
        <div class="field min-w-56 flex-1">
            <label for="search" class="label">{{ __('lang_v1.search') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search" placeholder="{{ $label }}">
            </div>
        </div>

        <button type="submit" class="btn-primary">
            <x-nav-icon name="filter"/>
            {{ __('lang_v1.apply') }}
        </button>

        @if ($isFiltered)
            <a href="{{ route($routePrefix.'.index') }}" class="btn-secondary">
                <x-nav-icon name="x" :size="4"/>
                {{ __('lang_v1.reset') }}
            </a>
        @endif
    </div>
</form>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                @foreach ($columns as $column => $heading)
                    <th>{{ __($heading) }}</th>
                @endforeach
                @if ($canUpdate || $canDelete)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                <tr>
                    @foreach ($columns as $column => $heading)
                        @php
                            $value = $record->{$column};
                            /* The first column identifies the row, so it carries the
                               weight — every other column is supporting detail. */
                            $isFirst = $loop->first;
                        @endphp
                        <td @class([
                            'cell-numeric' => is_numeric($value) && ! is_bool($value),
                            'cell-primary' => $isFirst && ! is_bool($value),
                        ])>
                            @if (is_bool($value))
                                <span class="{{ $value ? 'badge-success' : 'badge-muted' }}">
                                    {{ $value ? __('lang_v1.yes') : __('lang_v1.no') }}
                                </span>
                            @elseif ($value === null || $value === '')
                                <span class="text-slate-400">—</span>
                            @else
                                {{ $value }}
                            @endif
                        </td>
                    @endforeach

                    @if ($canUpdate || $canDelete)
                        <td>
                            {{-- Icon actions rather than text buttons: nine settings
                                 tables show the same two verbs on every row, and
                                 spelling them out doubles the row height for no
                                 gain. Each still carries an accessible name. --}}
                            <div class="cell-actions">
                                @if ($canUpdate)
                                    <a href="{{ route($routePrefix.'.edit', $record->id) }}"
                                       class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                       aria-label="{{ __('lang_v1.edit') }}">
                                        <x-nav-icon name="edit" :size="4"/>
                                    </a>
                                @endif

                                @if ($canDelete)
                                    <form method="POST"
                                          action="{{ route($routePrefix.'.destroy', $record->id) }}"
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
                <x-table-empty :columns="count($columns) + ($canUpdate || $canDelete ? 1 : 0)"
                               :icon="$isFiltered ? 'search' : 'box'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : null">
                    @if ($isFiltered)
                        <a href="{{ route($routePrefix.'.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canCreate)
                        <a href="{{ route($routePrefix.'.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.add') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $records->links() }}
</div>
@endsection
