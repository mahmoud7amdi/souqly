@extends('layouts.app')
@section('title', __('lang_v1.edit_payment'))
@section('page_title', __('lang_v1.edit_payment'))

@section('content')

<x-page-head :back="route('payments.show', $payment->id)"
             :backLabel="__('lang_v1.payment')"
             :subtitle="or_dash($payment->payment_ref_no)"/>

@include('payment._form')
@endsection
