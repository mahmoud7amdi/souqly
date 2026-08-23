@extends('layouts.app')
@section('title', __('lang_v1.import').' — '.__('lang_v1.contacts'))
@section('page_title', __('lang_v1.import').' — '.__('lang_v1.contacts'))

@section('content')

<x-page-head :back="route('contacts.index')" :backLabel="__('lang_v1.contacts')"/>

@include('import._form', [
    'action' => route('contacts.import'),
    'templateRoute' => route('contacts.import.template'),
    'columns' => $columns,
    'hints' => [__('lang_v1.import_contact_type_hint')],
])
@endsection
