@extends('layouts.app')
@section('title', __('lang_v1.add_payment'))
@section('page_title', __('lang_v1.add_payment'))

@section('content')

{{-- The back link points wherever the form would land on save — the invoice you
     came from, or the ledger — so cancelling and saving leave you in the same
     place. `returnUrl` is the controller's own answer to that question. --}}
<x-page-head :back="$returnUrl"
             :backLabel="$document ? __('lang_v1.back_to_document') : __('lang_v1.payments')"
             :subtitle="$document
                ? __('lang_v1.recording_against_one_document')
                : __('lang_v1.recording_against_a_balance')"/>

@include('payment._form')
@endsection
