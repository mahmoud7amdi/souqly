@extends('layouts.app')
@section('title', __('lang_v1.edit_expense'))
@section('page_title', __('lang_v1.edit_expense'))

@section('content')

<x-page-head :back="route('expenses.show', $expense->id)"
             :backLabel="__('lang_v1.expense')"
             :subtitle="or_dash($expense->ref_no)"/>

@include('expense._form')
@endsection
