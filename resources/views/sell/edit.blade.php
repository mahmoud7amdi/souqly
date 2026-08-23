@extends('layouts.app')
@section('title', __('lang_v1.edit').' — '.$document->invoice_no)
@section('page_title', __('lang_v1.edit').' — '.$document->invoice_no)

@section('content')
<form method="POST" action="{{ route($prefix.'.update', $document->id) }}">
    @csrf
    @method('PUT')

    {{-- The way back is the document, not the list: someone who opened edit from
         an invoice and changed their mind wants that invoice again. --}}
    <x-page-head :back="route($prefix.'.show', $document->id)"
                 :backLabel="$document->invoice_no"/>

    @include('sell._form')

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route($prefix.'.show', $document->id) }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.update') }}
        </button>
    </div>
</form>
@endsection
