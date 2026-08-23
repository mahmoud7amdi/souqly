@extends('layouts.app')
@section('title', __('lang_v1.edit').' '.$label)
@section('page_title', __('lang_v1.edit').' '.$label)

@section('content')

<x-page-head :subtitle="$record->name"
             :back="route($routePrefix.'.index')"
             :backLabel="__('lang_v1.variation_templates')"/>

<form method="POST" action="{{ route($routePrefix.'.update', $record->id) }}" class="card max-w-2xl">
    @csrf
    @method('PUT')

    <div class="card-body grid gap-4">
        @include('variation_template._form')
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
