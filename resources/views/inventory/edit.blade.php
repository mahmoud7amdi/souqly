@extends('layouts.app')
@section('title', __('lang_v1.edit_stock_count'))
@section('page_title', __('lang_v1.edit_stock_count'))

@section('content')

<form method="POST" action="{{ route('inventory.update', $record->id) }}">
    @csrf
    @method('PUT')

    {{-- Back to the count, not the list: the only reason to be here is that
         something about a count in progress was wrong. --}}
    <x-page-head :back="route('inventory.show', $record->id)" :backLabel="$record->name"/>

    @include('inventory._form')

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('inventory.show', $record->id) }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.update') }}
        </button>
    </div>
</form>
@endsection
