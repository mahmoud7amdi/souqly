<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"
      dir="{{ in_array(app()->getLocale(), config('constants.langs_rtl', []), true) ? 'rtl' : 'ltr' }}"
      class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#00a76f">
    <title>{{ __('lang_v1.login') }} — {{ config('constants.app_title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- Same canvas, same card, same control sizes as the authenticated app: the
     login screen is the first impression of the design system, not an exception
     to it. Brand band at the top so the page has a horizon rather than floating
     in grey. --}}
<body class="min-h-full bg-slate-100">

<div class="h-1.5 w-full bg-brand-600"></div>

<div class="grid min-h-[calc(100vh-0.375rem)] place-items-center p-4">
    <div class="w-full max-w-sm">

        <div class="mb-7 text-center">
            <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-brand-600
                         text-xl font-bold text-white shadow-raised">
                {{ mb_substr(config('constants.app_title'), 0, 1) }}
            </span>
            <h1 class="mt-4 text-xl font-bold text-slate-900">{{ config('constants.app_title') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('lang_v1.sign_in_to_continue') }}</p>
        </div>

        <form method="POST" action="{{ route('login') }}" class="card p-6">
            @csrf

            @if ($errors->any())
                <div class="alert-danger mb-5" role="alert">
                    <x-nav-icon name="alert"/>
                    <span class="min-w-0 font-medium">{{ $errors->first() }}</span>
                </div>
            @endif

            <div class="grid gap-5">
                <div class="field">
                    <label for="username" class="label label-required">{{ __('lang_v1.username') }}</label>
                    <div class="input-search-wrap">
                        <span class="input-search-icon"><x-nav-icon name="user"/></span>
                        <input id="username" name="username" value="{{ old('username') }}"
                               class="input-search @if($errors->has('username')) input-invalid @endif"
                               required autofocus autocomplete="username" dir="ltr">
                    </div>
                </div>

                <div class="field">
                    <label for="password" class="label label-required">{{ __('lang_v1.password') }}</label>
                    <div class="input-search-wrap">
                        <span class="input-search-icon"><x-nav-icon name="lock"/></span>
                        <input id="password" name="password" type="password"
                               class="input-search @if($errors->has('password')) input-invalid @endif"
                               required autocomplete="current-password" dir="ltr">
                    </div>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="remember" value="1" class="checkbox">
                    <span class="checkbox-label">{{ __('lang_v1.remember_me') }}</span>
                </label>

                {{-- `login`, not `logout`: the arrow enters the doorway. Both are
                     directional, so it also mirrors correctly in Arabic — the
                     logout glyph here pointed out of the door on a sign-in
                     button. --}}
                <button type="submit" class="btn-primary btn-block">
                    <x-nav-icon name="login"/>
                    {{ __('lang_v1.login') }}
                </button>
            </div>
        </form>

        {{-- Locale switch before login: the field labels above follow it. --}}
        <form method="GET" class="mt-5 text-center">
            <select name="lang" class="select mx-auto w-auto py-1.5 text-xs"
                    onchange="window.location = '?lang=' + this.value"
                    aria-label="{{ __('lang_v1.language') }}">
                @foreach (config('constants.langs') as $code => $lang)
                    <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ $lang['full_name'] }}</option>
                @endforeach
            </select>
        </form>
    </div>
</div>

</body>
</html>
