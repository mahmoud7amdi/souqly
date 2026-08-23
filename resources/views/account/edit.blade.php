@extends('layouts.app')
@section('title', __('lang_v1.edit_account'))
@section('page_title', __('lang_v1.edit_account'))

@section('content')

<x-page-head :back="route('accounts.show', $account->id)"
             :backLabel="$account->name"
             :subtitle="or_dash($account->account_number)"/>

@include('account._form')
@endsection
