@extends('layouts.app')
@section('title', __('accounting.add_account'))
@section('page_title', __('accounting.add_account'))

@section('content')

<x-page-head :back="route('accounting.accounts.index')"
             :backLabel="__('accounting.chart_of_accounts')"/>

<form method="POST" action="{{ route('accounting.accounts.store') }}">
    @csrf
    @include('accounting.accounts._form', ['record' => null])

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('accounting.accounts.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('accounting.add_account') }}
        </button>
    </div>
</form>
@endsection
