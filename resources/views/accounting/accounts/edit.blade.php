@extends('layouts.app')
@section('title', __('accounting.edit_account'))
@section('page_title', __('accounting.edit_account').' — '.$record->name)

@section('content')

<x-page-head :title="__('accounting.edit_account')"
             :back="route('accounting.accounts.show', $record->id)"
             :backLabel="$record->name"/>

<form method="POST" action="{{ route('accounting.accounts.update', $record->id) }}">
    @csrf
    @method('PUT')
    @include('accounting.accounts._form')

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('accounting.accounts.show', $record->id) }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.update') }}
        </button>
    </div>
</form>
@endsection
