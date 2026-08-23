@extends('layouts.app')
@section('title', __('lang_v1.profile'))
@section('page_title', __('lang_v1.profile'))

@section('content')

{{-- Capped: two columns of short text fields do not need the full width of a
     desktop monitor, and a 600px-wide input for a first name looks broken. --}}
<div class="grid max-w-4xl gap-6 lg:grid-cols-2">

    <form method="POST" action="{{ route('user.update') }}">
        @csrf

        <x-panel :title="__('lang_v1.profile')" icon="user">
            <div class="grid gap-4">
                <div class="field">
                    <label for="first_name" class="label label-required">{{ __('lang_v1.name') }}</label>
                    <input id="first_name" name="first_name"
                           @class(['input', 'input-invalid' => $errors->has('first_name')])
                           value="{{ old('first_name', $user->first_name) }}"
                           autocomplete="given-name" required>
                    @error('first_name')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="last_name" class="label">{{ __('lang_v1.last_name') }}</label>
                    <input id="last_name" name="last_name" class="input"
                           value="{{ old('last_name', $user->last_name) }}"
                           autocomplete="family-name">
                </div>

                <div class="field">
                    <label for="email" class="label">{{ __('lang_v1.email') }}</label>
                    <input id="email" name="email" type="email"
                           @class(['input force-ltr', 'input-invalid' => $errors->has('email')])
                           value="{{ old('email', $user->email) }}" autocomplete="email">
                    @error('email')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="contact_no" class="label">{{ __('lang_v1.contact_no') }}</label>
                    <input id="contact_no" name="contact_no" type="tel" class="input force-ltr"
                           inputmode="tel" value="{{ old('contact_no', $user->contact_no) }}"
                           autocomplete="tel">
                </div>

                <div class="field">
                    <label for="language" class="label label-required">{{ __('lang_v1.language') }}</label>
                    <select id="language" name="language" class="select">
                        @foreach (config('constants.langs') as $code => $lang)
                            <option value="{{ $code }}" @selected(old('language', $user->language) === $code)>
                                {{ $lang['full_name'] }}
                            </option>
                        @endforeach
                    </select>
                    {{-- Saving this reloads the app in the chosen language, so it is
                         worth saying before the click rather than after. --}}
                    <p class="hint">{{ __('lang_v1.language_change_hint') }}</p>
                </div>
            </div>

            <x-slot:footer>
                <button type="submit" class="btn-primary">
                    <x-nav-icon name="save"/>
                    {{ __('lang_v1.update') }}
                </button>
            </x-slot:footer>
        </x-panel>
    </form>

    <form method="POST" action="{{ route('user.updatePassword') }}" class="self-start">
        @csrf

        <x-panel :title="__('lang_v1.change_password')" icon="lock">
            {{-- The username is not on this form, so the browser has nothing to
                 attach the new credentials to. autocomplete tells the password
                 manager which box is which; without it, managers offer to save
                 the *current* password as the new one. --}}
            <div class="grid gap-4">
                <div class="field">
                    <label for="current_password" class="label label-required">
                        {{ __('lang_v1.current_password') }}
                    </label>
                    <input id="current_password" name="current_password" type="password"
                           @class(['input force-ltr', 'input-invalid' => $errors->has('current_password')])
                           autocomplete="current-password" required>
                    @error('current_password')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="password" class="label label-required">{{ __('lang_v1.new_password') }}</label>
                    <input id="password" name="password" type="password"
                           @class(['input force-ltr', 'input-invalid' => $errors->has('password')])
                           autocomplete="new-password" required>
                    @error('password')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="password_confirmation" class="label label-required">
                        {{ __('lang_v1.confirm_password') }}
                    </label>
                    <input id="password_confirmation" name="password_confirmation" type="password"
                           class="input force-ltr" autocomplete="new-password" required>
                </div>
            </div>

            <x-slot:footer>
                <button type="submit" class="btn-primary">
                    <x-nav-icon name="key"/>
                    {{ __('lang_v1.update') }}
                </button>
            </x-slot:footer>
        </x-panel>
    </form>
</div>
@endsection
