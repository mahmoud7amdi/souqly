@extends('layouts.app')
@section('title', __('lang_v1.add').' '.$label)
@section('page_title', __('lang_v1.add').' '.$label)

@section('content')

<x-page-head :back="route($routePrefix.'.index')" :backLabel="$label"/>

<form method="POST" action="{{ route($routePrefix.'.store') }}" class="max-w-3xl">
    @csrf

    @include('invoice-layout._form', ['record' => null])

    <div class="form-actions">
        <a href="{{ route($routePrefix.'.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endsection
