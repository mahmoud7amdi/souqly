{{--
    Page head — the strip between the sticky header and the content.

    Deliberately has NO title by default. The layout's sticky header already
    renders @yield('page_title'), and repeating the same word 60px below it wasted
    a row and read as a mistake. So the division is:

        sticky header → the section you are in ("Sales"), always visible
        page head     → what you are looking at *specifically* and what you can
                        do about it ("INV-2026-0042", [Print] [Payment])

    An index screen therefore passes a `subtitle` that carries information the
    header cannot ("240 invoices · EGP 1.2M due") instead of its own name, and a
    detail screen passes the record's identifier as `title`.
--}}
@props([
    'title' => null,
    'subtitle' => null,
    'back' => null,
    'backLabel' => null,
])

<div class="page-head">
    <div class="page-head-text">
        @if ($back)
            <a href="{{ $back }}" class="page-back">
                <x-nav-icon name="chevron-back" :size="4"/>
                {{ $backLabel ?? __('lang_v1.back_to_list') }}
            </a>
        @endif

        @if ($title)
            <p class="page-title">{{ $title }}</p>
        @endif

        @if ($subtitle)
            <p class="page-subtitle">{{ $subtitle }}</p>
        @endif
    </div>

    {{-- Actions always sit at the inline end, on every screen, so the eye finds
         the commit button in one place. --}}
    @if (! $slot->isEmpty())
        <div class="page-actions no-print">{{ $slot }}</div>
    @endif
</div>
