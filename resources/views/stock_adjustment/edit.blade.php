@extends('layouts.app')
@section('title', __('lang_v1.edit').' — '.$document->ref_no)
@section('page_title', __('lang_v1.edit').' — '.$document->ref_no)

@section('content')
<form method="POST" action="{{ route('stock-adjustments.update', $document->id) }}">
    @csrf
    @method('PUT')

    {{-- Back to the document, not the list: someone who opened edit from an
         adjustment and changed their mind wants that adjustment again. --}}
    <x-page-head :back="route('stock-adjustments.show', $document->id)"
                 :backLabel="$document->ref_no"/>

    {{-- Saving rewrites the document from scratch — the whole thing is reversed
         and recorded again (StockAdjustmentService::update explains why). That is
         invisible and harmless except in one case worth warning about: if the
         written-off stock has since been re-consumed elsewhere, putting it back
         and taking it again may no longer fit. --}}
    <div class="alert-info mb-5" role="note">
        <x-nav-icon name="info"/>
        <div class="min-w-0">
            <p class="font-semibold">{{ __('lang_v1.editing_rewrites_document') }}</p>
            <p class="mt-0.5">{{ __('lang_v1.adjustment_edit_hint') }}</p>
        </div>
    </div>

    @include('stock_adjustment._form')

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('stock-adjustments.show', $document->id) }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.update') }}
        </button>
    </div>
</form>
@endsection
