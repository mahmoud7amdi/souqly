@extends('layouts.app')
@section('title', __('assetmanagement.edit_asset'))
@section('page_title', __('assetmanagement.edit_asset'))

@section('content')

<x-page-head :subtitle="$record->name.' — '.$record->asset_code"
             :back="route('assets.show', $record->id)"
             :backLabel="__('assetmanagement.back_to_asset')"/>

<form method="POST" action="{{ route('assets.update', $record->id) }}">
    @csrf
    @method('PUT')
    @include('asset._form')

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('assets.show', $record->id) }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endsection
