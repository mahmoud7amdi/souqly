@php
    /* sells → sell, sales-order → sales_order. Hoisted above @extends so both the
       header title and the back link can use it.

       The plural is mapped rather than derived: `sell` + 's' would ask for a
       `sells` key that means exactly what the existing `sales` key already means,
       and two keys for one word is how translations drift apart. */
    $typeKey = $prefix === 'sells' ? 'sell' : str_replace('-', '_', $prefix);
    $typePlural = $prefix === 'sells' ? 'sales' : str_replace('-', '_', $prefix).'s';
@endphp

@extends('layouts.app')
@section('title', __('lang_v1.add'))
@section('page_title', __('lang_v1.add').' — '.__('lang_v1.'.$typeKey))

@section('content')

{{-- No title: the sticky header already says "Add — Sale", and repeating it here
     costs a row and reads as a mistake. The back link is the point. --}}
<x-page-head :back="route($prefix.'.index')" :backLabel="__('lang_v1.'.$typePlural)"/>

<form method="POST" action="{{ route($prefix.'.store') }}">
    @csrf
    @include('sell._form', ['document' => null])

    {{-- Sticky, unlike the settings forms' card footer: this form is five panels
         and a line table tall, so a commit button pinned to the bottom of the
         document would sit two screens below the field being filled in. --}}
    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route($prefix.'.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endsection
