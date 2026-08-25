@extends('layouts.app')
@section('title', __('assetmanagement.add_asset'))
@section('page_title', __('assetmanagement.add_asset'))

@section('content')

<x-page-head :back="route('assets.index')" :backLabel="__('assetmanagement.assets')"/>

<form method="POST" action="{{ route('assets.store') }}">
    @csrf
    @include('asset._form', ['record' => null, 'allocated' => 0.0])

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('assets.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('assetmanagement.add_asset') }}
        </button>
    </div>
</form>
@endsection
