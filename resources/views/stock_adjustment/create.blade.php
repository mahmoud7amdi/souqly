@extends('layouts.app')
@section('title', __('lang_v1.add_stock_adjustment'))
@section('page_title', __('lang_v1.add_stock_adjustment'))

@section('content')

<x-page-head :back="route('stock-adjustments.index')" :backLabel="__('lang_v1.stock_adjustments')"/>

<form method="POST" action="{{ route('stock-adjustments.store') }}">
    @csrf
    @include('stock_adjustment._form', ['document' => null])

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('stock-adjustments.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endsection
