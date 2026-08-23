@extends('layouts.app')
@section('title', __('lang_v1.add').' '.$label)
@section('page_title', __('lang_v1.add').' '.$label)

@section('content')

<x-page-head :back="route($routePrefix.'.index')" :backLabel="__('lang_v1.variation_templates')"/>

{{-- Capped width, same as the settings forms: two fields stretched across a wide
     monitor give 1200px-long inputs for a 20-character name. --}}
<form method="POST" action="{{ route($routePrefix.'.store') }}" class="card max-w-2xl">
    @csrf

    <div class="card-body grid gap-4">
        @include('variation_template._form', ['record' => null])
    </div>

    <div class="card-actions">
        <a href="{{ route($routePrefix.'.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endsection
