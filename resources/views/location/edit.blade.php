@extends('layouts.app')
@section('title', __('lang_v1.edit').' '.$label)
@section('page_title', __('lang_v1.edit').' '.$label)

@section('content')

<x-page-head :subtitle="$record->name ?? null"
             :back="route($routePrefix.'.index')"
             :backLabel="$label"/>

<form method="POST" action="{{ route($routePrefix.'.update', $record->id) }}" class="max-w-3xl">
    @csrf
    @method('PUT')

    @include('location._form')

    <div class="form-actions">
        <a href="{{ route($routePrefix.'.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.update') }}
        </button>
    </div>
</form>
@endsection
