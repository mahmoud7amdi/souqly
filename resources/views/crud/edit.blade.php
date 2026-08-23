@extends('layouts.app')
@section('title', __('lang_v1.edit').' '.$label)
@section('page_title', __('lang_v1.edit').' '.$label)

@section('content')

{{-- The subtitle stays: "Edit — Brand" in the sticky header says what kind of
     record, and only this line says which one. --}}
<x-page-head :subtitle="$record->name ?? null"
             :back="route($routePrefix.'.index')"
             :backLabel="$label"/>

<form method="POST" action="{{ route($routePrefix.'.update', $record->id) }}" class="card max-w-2xl">
    @csrf
    @method('PUT')

    <div class="card-body form-grid">
        @include('crud._form')
    </div>

    <div class="card-actions">
        <a href="{{ route($routePrefix.'.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.update') }}
        </button>
    </div>
</form>
@endsection
