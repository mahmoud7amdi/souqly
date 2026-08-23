@extends('layouts.app')
@section('title', __('lang_v1.add_product'))
@section('page_title', __('lang_v1.add_product'))

@section('content')

{{-- No title: the sticky header already says "Add product". The back link is
     the point of the head on a form. --}}
<x-page-head :back="route('products.index')" :backLabel="__('lang_v1.products')"/>

<form method="POST" action="{{ route('products.store') }}">
    @csrf
    @include('product._form', ['product' => null])

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('products.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>

        {{-- Two ways to commit, and the difference is what happens next: this one
             hands you straight to the opening-stock screen, so it keeps the
             secondary treatment even though it does more. --}}
        <button type="submit" name="submit_type" value="submit_n_add_opening_stock" class="btn-secondary">
            <x-nav-icon name="box"/>
            {{ __('lang_v1.save_and_add_opening_stock') }}
        </button>

        <button type="submit" name="submit_type" value="save" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endsection
