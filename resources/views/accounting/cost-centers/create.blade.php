@extends('layouts.app')
@section('title', __('accounting.add_cost_center'))
@section('page_title', __('accounting.add_cost_center'))

@section('content')

<x-page-head :back="route('accounting.cost-centers.index')"
             :backLabel="__('accounting.cost_centers')"/>

<form method="POST" action="{{ route('accounting.cost-centers.store') }}">
    @csrf
    @include('accounting.cost-centers._form', ['record' => null])

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('accounting.cost-centers.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('accounting.add_cost_center') }}
        </button>
    </div>
</form>
@endsection
