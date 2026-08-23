@extends('layouts.app')
@section('title', __('lang_v1.edit'))
@section('page_title', __('lang_v1.edit').' — '.$contact->full_name)

@section('content')

{{-- Back to the contact, not the list: someone who opened edit from a contact
     and changed their mind wants that contact again. --}}
<x-page-head :back="route('contacts.show', $contact->id)" :backLabel="$contact->full_name"/>

<form method="POST" action="{{ route('contacts.update', $contact->id) }}">
    @csrf
    @method('PUT')
    @include('contact._form')

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.required_fields_hint') }}</span>
        <a href="{{ route('contacts.show', $contact->id) }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.update') }}
        </button>
    </div>
</form>
@endsection
