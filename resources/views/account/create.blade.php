@extends('layouts.app')
@section('title', __('lang_v1.add_account'))
@section('page_title', __('lang_v1.add_account'))

@section('content')

<x-page-head :back="route('accounts.index')"
             :backLabel="__('lang_v1.payment_accounts')"
             :subtitle="__('lang_v1.accounts_are_where_money_sits')"/>

@include('account._form')
@endsection
