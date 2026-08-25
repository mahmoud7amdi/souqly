@extends('layouts.app')
@section('title', __('assetmanagement.raise_job'))
@section('page_title', __('assetmanagement.raise_job'))

@section('content')

<x-page-head :back="route('asset-maintenance.index')" :backLabel="__('assetmanagement.maintenance')"/>

<form method="POST" action="{{ route('asset-maintenance.store') }}">
    @csrf
    @include('asset_maintenance._form', ['record' => null])

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('asset-maintenance.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('assetmanagement.raise_job') }}
        </button>
    </div>
</form>
@endsection
