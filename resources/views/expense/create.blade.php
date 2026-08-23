@extends('layouts.app')
@section('title', __('lang_v1.add_expense'))
@section('page_title', __('lang_v1.add_expense'))

@section('content')

<x-page-head :back="route('expenses.index')" :backLabel="__('lang_v1.expenses')"/>

@include('expense._form')
@endsection
