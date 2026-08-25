@extends('layouts.app')
@section('title', __('assetmanagement.edit_job'))
@section('page_title', __('assetmanagement.edit_job'))

@section('content')

<x-page-head :subtitle="$record->asset->name ?? null"
             :back="route('asset-maintenance.index')"
             :backLabel="__('assetmanagement.maintenance')">
    @can('asset.view')
        @if ($record->asset)
            <a href="{{ route('assets.show', $record->asset_id) }}" class="btn-secondary">
                <x-nav-icon name="box"/>
                {{ __('assetmanagement.open_asset') }}
            </a>
        @endif
    @endcan
</x-page-head>

<form method="POST" action="{{ route('asset-maintenance.update', $record->id) }}">
    @csrf
    @method('PUT')
    @include('asset_maintenance._form')

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('asset-maintenance.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endsection
