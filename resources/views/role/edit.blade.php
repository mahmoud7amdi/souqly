@extends('layouts.app')
@section('title', __('lang_v1.edit').' '.__('lang_v1.role'))
@section('page_title', __('lang_v1.edit').' '.__('lang_v1.role'))

@section('content')

<x-page-head :subtitle="$role->display_name"
             :back="route('roles.index')"
             :backLabel="__('lang_v1.roles')"/>

<form method="POST" action="{{ route('roles.update', $role->id) }}">
    @csrf
    @method('PUT')

    @include('role._form')

    <div class="form-actions">
        <a href="{{ route('roles.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.update') }}
        </button>
    </div>
</form>
@endsection
