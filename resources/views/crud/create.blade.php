@extends('layouts.app')
@section('title', __('lang_v1.add').' '.$label)
@section('page_title', __('lang_v1.add').' '.$label)

@section('content')

{{-- No title: the sticky header already reads "Add — Brand", and a page head
     that repeats it costs a row and reads as a mistake. Every form in the app
     follows the same rule — the head carries the way back and the actions. --}}
<x-page-head :back="route($routePrefix.'.index')" :backLabel="$label"/>

{{-- Capped width: a settings form with three fields stretched across a wide
     monitor gives 1200px-long inputs for a 20-character name. --}}
<form method="POST" action="{{ route($routePrefix.'.store') }}" class="card max-w-2xl">
    @csrf

    <div class="card-body form-grid">
        @include('crud._form', ['record' => null])
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
