<!DOCTYPE html>
<html lang="{{ $current_locale ?? app()->getLocale() }}"
      dir="{{ $text_direction ?? 'ltr' }}"
      class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#00a76f">
    <title>@yield('title', __('lang_v1.dashboard')) — {{ $app_title ?? config('constants.app_title') }}</title>

    {{-- Cairo covers Arabic and Latin in one family so metrics don't shift when
         the locale changes. It is self-hosted through Vite (see vite.config.js),
         not linked from a CDN: @vite injects the generated @font-face sheet and
         its preload hints, so the register keeps its typeface offline. --}}

    @if ($pwa_enabled ?? false)
        <link rel="manifest" href="{{ route('pwa.manifest') }}">
        <meta name="apple-mobile-web-app-capable" content="yes">
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="h-full">

{{-- Keyboard users land here first; a POS operator never sees it. --}}
<a href="#main" class="sr-only-focusable btn-primary btn-sm fixed top-2 start-2 z-50">
    {{ __('lang_v1.skip_to_content') }}
</a>

<div class="flex min-h-full">

    {{-- ================= Sidebar =================
         Off-canvas below lg, permanent above it. Uses logical `start-0` plus a
         direction-aware translate so one rule serves LTR and RTL. --}}
    <aside id="sidebar"
           class="fixed inset-y-0 start-0 z-40 flex w-64 -translate-x-full flex-col
                  border-e border-slate-200 bg-white transition-transform rtl:translate-x-full
                  lg:translate-x-0 lg:rtl:translate-x-0 no-print"
           aria-label="{{ __('lang_v1.main_navigation') }}">

        <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-slate-200 px-5">
            <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-600
                         text-sm font-bold text-white">
                {{ mb_substr(session('business.name', 'S'), 0, 1) }}
            </span>
            <span class="min-w-0">
                <span class="block truncate text-sm font-bold text-slate-900">
                    {{ session('business.name', config('constants.app_title')) }}
                </span>
                @if (session('business.location_name'))
                    <span class="block truncate text-xs text-slate-500">
                        {{ session('business.location_name') }}
                    </span>
                @endif
            </span>
        </div>

        <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 pb-6">
            @include('layouts.partials.sidebar')
        </nav>
    </aside>

    {{-- Sidebar scrim on mobile --}}
    <div id="sidebar-scrim"
         class="fixed inset-0 z-30 hidden bg-slate-900/50 backdrop-blur-sm lg:hidden no-print"
         aria-hidden="true"></div>

    {{-- ================= Main ================= --}}
    <div class="flex min-w-0 flex-1 flex-col lg:ms-64">

        {{-- One header on every screen: navigation toggle, where-am-I title,
             then account-level controls pinned to the inline end. --}}
        <header class="sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-slate-200
                       bg-white/95 px-4 backdrop-blur lg:px-6 no-print">
            <button type="button" id="sidebar-toggle"
                    class="btn-icon lg:hidden"
                    aria-label="{{ __('lang_v1.toggle_navigation') }}"
                    aria-controls="sidebar" aria-expanded="false">
                <x-nav-icon name="menu"/>
            </button>

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-base font-bold text-slate-900 lg:text-lg">
                    @yield('page_title', __('lang_v1.dashboard'))
                </h1>
            </div>

            {{-- Offline / sync indicator (PWA) --}}
            @if ($pwa_enabled ?? false)
                <span id="connection-status" class="badge-success"
                      data-online-label="{{ __('lang_v1.online') }}"
                      data-offline-label="{{ __('lang_v1.offline') }}">
                    <x-nav-icon name="wifi" :size="4"/>
                    <span class="hidden sm:inline">{{ __('lang_v1.online') }}</span>
                </span>
            @endif

            @include('layouts.partials.locale-switcher')
            @include('layouts.partials.notifications')
            @include('layouts.partials.user-menu')
        </header>

        {{-- Content is capped and centred: a data table stretched across a 27"
             monitor is unreadable, because the eye loses the row on the way from
             the name to the figure. Screens that genuinely need the full width
             (the POS terminal, label sheets) declare @section('full_bleed'). --}}
        <main id="main" class="flex-1 p-4 lg:p-6">
            <div @class([
                'w-full',
                'mx-auto max-w-[96rem]' => ! View::hasSection('full_bleed'),
            ])>
                @include('components.status-banner')

                @yield('content')
            </div>
        </main>

        <footer class="border-t border-slate-200 px-4 py-4 text-xs text-slate-500 lg:px-6 no-print">
            {{ $app_title ?? config('constants.app_title') }}
            &middot; {{ __('lang_v1.all_rights_reserved') }}
        </footer>
    </div>
</div>

@stack('modals')
@stack('scripts')
</body>
</html>
