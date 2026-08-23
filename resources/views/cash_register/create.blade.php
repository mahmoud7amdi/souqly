@extends('layouts.app')
@section('title', __('lang_v1.open_register'))
@section('page_title', __('lang_v1.cash_register'))

@section('content')

<x-page-head :title="__('lang_v1.open_register')"
             :back="route('cash-register.index')"
             :backLabel="__('lang_v1.cash_register')"
             :subtitle="__('lang_v1.a_shift_starts_here')"/>

{{--
    Two fields, and that is deliberate. Opening a register is the thing standing
    between a cashier and the first customer of the day, so it asks for the least
    that still makes the close meaningful: which location the drawer belongs to,
    and what was already in it. Everything else is counted at the end of the shift
    ({@see cash_register/close.blade.php}), where there is time to count.
--}}
<form method="POST" action="{{ route('cash-register.store') }}">
    @csrf

    <div class="max-w-2xl">
        <x-panel :title="__('lang_v1.opening_the_drawer')" icon="cash">
            <div class="form-grid">
                <div class="field">
                    <label for="location_id" class="label label-required">
                        {{ __('lang_v1.location') }}
                    </label>
                    <select id="location_id" name="location_id"
                            class="select @error('location_id') input-invalid @enderror" required autofocus>
                        @foreach ($locations as $id => $name)
                            <option value="{{ $id }}" @selected(old('location_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('location_id')
                        <p class="field-error">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('lang_v1.sales_from_this_shift_belong_here') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="opening_amount" class="label">{{ __('lang_v1.opening_float') }}</label>
                    <input id="opening_amount" name="opening_amount" type="text" inputmode="decimal"
                           value="{{ old('opening_amount') }}"
                           class="input input-numeric input-lg @error('opening_amount') input-invalid @enderror"
                           placeholder="0.00">
                    @error('opening_amount')
                        <p class="field-error">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('lang_v1.cash_already_in_the_drawer') }}</p>
                    @enderror
                </div>
            </div>
        </x-panel>
    </div>

    {{-- The accent button is first in document order so that pressing Enter in the
         float field starts the shift, which is what the cashier came to do. The
         visual order is reversed, keeping the commit at the inline end where every
         other screen puts it. --}}
    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.one_open_register_per_user') }}</span>

        <button type="submit" class="btn-accent order-3">
            <x-nav-icon name="pos" :size="4"/>
            {{ __('lang_v1.open_and_start_selling') }}
        </button>

        <button type="submit" name="open_and_view" value="1" class="btn-secondary order-2">
            {{ __('lang_v1.open_and_view_register') }}
        </button>

        <a href="{{ route('cash-register.index') }}" class="btn-secondary order-1">
            {{ __('lang_v1.cancel') }}
        </a>
    </div>
</form>
@endsection
