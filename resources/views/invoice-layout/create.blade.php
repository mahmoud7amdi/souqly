@extends('layouts.app')
@section('title', __('lang_v1.add').' '.$label)
@section('page_title', __('lang_v1.add').' '.$label)

@section('content')

<x-page-head :back="route($routePrefix.'.index')" :backLabel="$label"/>

{{-- `enctype`: the layout carries a logo and a letterhead, and a plain
     urlencoded form posts a file input as its filename and nothing else. --}}
<form method="POST" action="{{ route($routePrefix.'.store') }}" enctype="multipart/form-data"
      class="max-w-3xl">
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
