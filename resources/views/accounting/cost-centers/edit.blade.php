@extends('layouts.app')
@section('title', __('accounting.edit_cost_center'))
@section('page_title', __('accounting.edit_cost_center').' — '.$record->name)

@section('content')

{{--
    Back and cancel both lead to the listing, not to a record page as they do on the
    account form. There is no cost-centre show route to return to — the listing is
    the record page — so pointing either at one would be a link to a 404.
--}}

<x-page-head :title="__('accounting.edit_cost_center')"
             :back="route('accounting.cost-centers.index')"
             :backLabel="__('accounting.cost_centers')"
             :subtitle="$record->code.' — '.$record->name"/>

<form method="POST" action="{{ route('accounting.cost-centers.update', $record->id) }}">
    @csrf
    @method('PUT')
    @include('accounting.cost-centers._form')

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('accounting.cost-centers.index') }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.update') }}
        </button>
    </div>
</form>
@endsection
