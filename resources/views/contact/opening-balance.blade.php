@extends('layouts.app')
@section('title', __('lang_v1.opening_balance'))
@section('page_title', $contact->full_name.' — '.__('lang_v1.opening_balance'))

@section('content')

<x-page-head :back="route('contacts.show', $contact->id)" :backLabel="$contact->full_name"/>

{{-- Capped width, and no sticky .form-actions: one field does not scroll, so the
     commit button belongs in the card it commits rather than pinned to the
     viewport. Same rule the settings forms follow. --}}
<form method="POST" action="{{ route('contacts.openingBalance.update', $contact->id) }}"
      class="card max-w-lg">
    @csrf

    <div class="card-body grid gap-5">
        <div class="alert-info">
            <span>{{ __('lang_v1.opening_balance_hint') }}</span>
        </div>

        <div class="field">
            <label for="opening_balance" class="label label-required">
                {{ __('lang_v1.opening_balance') }}
            </label>
            <input id="opening_balance" name="opening_balance"
                   @class(['input-numeric', 'input-invalid' => $errors->has('opening_balance')])
                   inputmode="decimal" value="{{ old('opening_balance', $amount) }}" required>
            @error('opening_balance')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="card-actions">
        <a href="{{ route('contacts.show', $contact->id) }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.save') }}
        </button>
    </div>
</form>
@endsection
