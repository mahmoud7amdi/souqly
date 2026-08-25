@extends('layouts.app')
@section('title', __('lang_v1.add_stock_count'))
@section('page_title', __('lang_v1.add_stock_count'))

@section('content')

<x-page-head :back="route('inventory.index')" :backLabel="__('lang_v1.stock_counts')"/>

<form method="POST" action="{{ route('inventory.store') }}">
    @csrf
    @include('inventory._form', ['record' => null, 'branchLocked' => false])

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('inventory.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.record_a_count') }}
        </button>
    </div>
</form>
@endsection
