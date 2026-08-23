@extends('layouts.app')
@section('title', __('lang_v1.variation_templates'))
@section('page_title', __('lang_v1.variation_templates'))

@section('content')

@php
    /* Whether the list is empty because nothing exists yet or because a filter
       excluded everything — "add your first template" and "your search matched
       nothing" are different messages. */
    $isFiltered = request()->filled('search');

    $columnCount = 2 + ($canUpdate || $canDelete ? 1 : 0);
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $records->total(), ['count' => $records->total()])">
    @if ($canCreate)
        <a href="{{ route($routePrefix.'.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.add') }}
        </a>
    @endif
</x-page-head>

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
                <th>{{ __('lang_v1.name') }}</th>
                <th>{{ __('lang_v1.values') }}</th>
                @if ($canUpdate || $canDelete)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                <tr>
                    <td class="cell-primary">{{ $record->name }}</td>

                    <td>
                        {{-- The values are what the template *is*, so they are listed
                             in full rather than counted: "Size" tells you nothing,
                             "S M L XL" tells you whether it is the one you want. --}}
                        @if ($record->values->isNotEmpty())
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($record->values as $value)
                                    <span class="badge-muted">{{ $value->name }}</span>
                                @endforeach
                            </div>
                        @else
                            <span class="cell-none">{{ __('lang_v1.no_records_found') }}</span>
                        @endif
                    </td>

                    @if ($canUpdate || $canDelete)
                        <td>
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
                <x-table-empty :columns="$columnCount"
                               :icon="$isFiltered ? 'search' : 'layers'"
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
