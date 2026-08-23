@extends('layouts.app')
@section('title', __('lang_v1.import_products'))
@section('page_title', __('lang_v1.import_products'))

@section('content')

<x-page-head :back="route('products.index')" :backLabel="__('lang_v1.products')"/>

@include('import._form', [
    'action' => route('import-products.store'),
    'templateRoute' => route('import-products.template'),
    'columns' => $columns,
])
@endsection
