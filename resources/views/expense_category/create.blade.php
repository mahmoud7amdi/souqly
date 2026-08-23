@extends('layouts.app')
@section('title', __('lang_v1.add_category'))
@section('page_title', __('lang_v1.add_category'))

@section('content')

<x-page-head :back="route('expense-categories.index')"
             :backLabel="__('lang_v1.expense_categories')"/>

@include('expense_category._form')
@endsection
