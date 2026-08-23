@extends('layouts.app')
@section('title', __('lang_v1.add'))
@section('page_title', __('lang_v1.add').' — '.__('lang_v1.contacts'))

@section('content')

<x-page-head :back="route('contacts.index')" :backLabel="__('lang_v1.contacts')"/>

<form method="POST" action="{{ route('contacts.store') }}">
    @csrf
    @include('contact._form', ['contact' => null])

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('contacts.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endsection
