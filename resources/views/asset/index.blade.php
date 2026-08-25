@extends('layouts.app')
@section('title', __('assetmanagement.assets'))
@section('page_title', __('assetmanagement.assets'))

@section('content')

@php
    $isFiltered = collect(['search', 'location_id', 'state'])
        ->contains(fn ($key) => request()->filled($key));

    $canAdd = auth()->user()->can('asset.create');
    $canEdit = auth()->user()->can('asset.update');
    $canDelete = auth()->user()->can('asset.delete');

    $columnCount = 6 + (int) ($canEdit || $canDelete);
@endphp

<x-page-head :subtitle="__('assetmanagement.assets_subtitle')">
    @if ($canAdd)
        <a href="{{ route('assets.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('assetmanagement.add_asset') }}
        </a>
    @endif
</x-page-head>

{{-- Four figures, and the order is the order the questions get asked: how many do
     we own, what did they cost, how many are out of the building, and what is
     waiting to be fixed. The cost tile is acquisition cost rather than book value —
     see AssetService::summary() for why the headline and the rows would otherwise
     disagree by a rounding of a year. --}}
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('assetmanagement.total_assets')"
                :value="format_quantity($totals['assets'])"
                icon="box"/>

        <x-stat :label="__('assetmanagement.acquisition_cost')"
                :value="format_currency($totals['cost'])"
                icon="receipt"
                :hint="__('assetmanagement.before_depreciation')"/>

        <x-stat :label="__('assetmanagement.allocated_out')"
                :value="format_quantity($totals['allocated_qty'])"
                icon="user-plus"
                :hint="__('assetmanagement.across_n_assets', ['count' => format_quantity($totals['allocated_assets'])])"/>

        <x-stat :label="__('assetmanagement.open_maintenance')"
                :value="format_quantity($totals['open_maintenance'])"
                icon="cog"
                :tone="$totals['open_maintenance'] > 0 ? 'warning' : null"/>
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
                       placeholder="{{ __('assetmanagement.search_assets_placeholder') }}">
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
            <label for="state" class="label">{{ __('assetmanagement.allocation_state') }}</label>
            <select id="state" name="state" class="select">
                @foreach ($states as $value => $name)
                    <option value="{{ $value }}" @selected(request('state') === (string) $value)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('assets.index') }}" class="btn-secondary">
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
                <th>{{ __('assetmanagement.asset_code') }}</th>
                <th>{{ __('lang_v1.name') }}</th>
                <th>{{ __('lang_v1.business_location') }}</th>
                <th class="th-numeric">{{ __('lang_v1.quantity') }}</th>
                <th class="th-numeric">{{ __('assetmanagement.current_value') }}</th>
                <th>{{ __('lang_v1.status') }}</th>
                @if ($canEdit || $canDelete)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $asset)
                @php
                    $allocated = $asset->allocated_quantity;
                    $available = $asset->available_quantity;
                @endphp
                <tr>
                    <td class="whitespace-nowrap force-ltr">{{ $asset->asset_code }}</td>

                    <td>
                        <a href="{{ route('assets.show', $asset->id) }}" class="cell-link">
                            {{ $asset->name }}
                        </a>
                        {{-- Model and serial identify which of nine identical
                             monitors this row is, so they belong under the name
                             rather than in two mostly-empty columns of their own. --}}
                        @if ($asset->model || $asset->serial_no)
                            <span class="cell-meta force-ltr">
                                {{ collect([$asset->model, $asset->serial_no])->filter()->join(' · ') }}
                            </span>
                        @endif
                    </td>

                    <td>{{ or_dash($asset->businessLocation->name ?? null) }}</td>

                    {{-- The whole quantity, with what is signed out qualifying it.
                         Two columns would be blank on every asset nobody has taken,
                         which is most of them. --}}
                    <td class="cell-numeric">
                        @format_quantity($asset->quantity)
                        @if ($allocated > 0)
                            <span class="cell-meta">
                                {{ __('assetmanagement.n_out', ['qty' => format_quantity($allocated)]) }}
                            </span>
                        @endif
                    </td>

                    <td class="cell-numeric">
                        @format_currency($asset->current_value)
                        @if ($asset->depreciation)
                            <span class="cell-meta">
                                {{ __('assetmanagement.depreciating_at', ['rate' => format_number($asset->depreciation)]) }}
                            </span>
                        @endif
                    </td>

                    <td>
                        <div class="flex flex-wrap items-center gap-1">
                            @if (! $asset->is_allocatable)
                                <span class="badge-muted">{{ __('assetmanagement.not_allocatable') }}</span>
                            @elseif ($allocated > 0 && $available <= 0)
                                <span class="badge-brand">{{ __('assetmanagement.fully_allocated') }}</span>
                            @elseif ($allocated > 0)
                                <span class="badge-warning">{{ __('assetmanagement.partly_allocated') }}</span>
                            @else
                                <span class="badge-success">{{ __('assetmanagement.available') }}</span>
                            @endif

                            @if ($asset->is_in_warranty)
                                <span class="badge-info">{{ __('assetmanagement.in_warranty') }}</span>
                            @endif
                        </div>
                    </td>

                    @if ($canEdit || $canDelete)
                        <td>
                            <div class="cell-actions">
                                @if ($canEdit)
                                    <a href="{{ route('assets.edit', $asset->id) }}"
                                       class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                       aria-label="{{ __('lang_v1.edit') }}">
                                        <x-nav-icon name="edit" :size="4"/>
                                    </a>
                                @endif

                                {{-- Hidden while anything is signed out: the service
                                     refuses that delete, and a button whose only
                                     outcome is an error message is a lie about what
                                     is possible. --}}
                                @if ($canDelete && $allocated <= 0)
                                    <form method="POST" action="{{ route('assets.destroy', $asset->id) }}"
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
                               :icon="$isFiltered ? 'search' : 'box'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('assetmanagement.no_assets_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('assetmanagement.no_assets_yet_desc')">
                    @if ($isFiltered)
                        <a href="{{ route('assets.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canAdd)
                        <a href="{{ route('assets.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('assetmanagement.add_asset') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $records->links() }}
</div>
@endsection
