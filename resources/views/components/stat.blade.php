{{--
    Headline figure tile.

    `value` is pre-formatted by the caller (@format_currency / @format_quantity),
    because the tile cannot know whether it is showing money, a count or a
    quantity — and formatting is locale- and business-dependent.

    `tone` colours the value only, never the tile: a red *background* on a due
    figure turns a routine balance into an emergency. Money owed is worth
    colouring, so `tone="danger"` reddens the numeral and leaves the card calm.
--}}
@props([
    'label',
    'value',
    'icon' => null,
    'hint' => null,
    'tone' => null,
])

@php
    $toneClass = match ($tone) {
        'danger' => 'text-rose-700',
        'success' => 'text-emerald-700',
        'warning' => 'text-amber-700',
        default => '',
    };
@endphp

<div class="stat">
    <div class="stat-head">
        <span class="stat-label">{{ $label }}</span>
        @if ($icon)
            <span class="stat-icon"><x-nav-icon :name="$icon" :size="4"/></span>
        @endif
    </div>

    <span class="stat-value {{ $toneClass }}">{{ $value }}</span>

    @if ($hint)
        <span class="stat-hint">{{ $hint }}</span>
    @endif
</div>
