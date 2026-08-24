@extends('layouts.app')
@section('title', __('lang_v1.add').' '.__('lang_v1.user'))
@section('page_title', __('lang_v1.add').' '.__('lang_v1.user'))

@section('content')

<x-page-head :back="route('users.index')" :backLabel="__('lang_v1.users')"/>

<form method="POST" action="{{ route('users.store') }}" class="max-w-4xl" autocomplete="off">
    @csrf

    @include('manage_user._form')

    <div class="form-actions">
        <a href="{{ route('users.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endsection
