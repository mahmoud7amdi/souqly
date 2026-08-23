{{--
    Empty state.

    An empty table or panel is a dead end without one of these: the user cannot
    tell "nothing matches your filter" from "this feature is broken". So every
    empty list says which of the two it is, and where relevant offers the action
    that would fill it (passed in the slot).

    `icon` defaults to a neutral tray rather than a warning glyph — an empty list
    is usually not a problem, and a red triangle on a fresh install is alarming
    for no reason.

    `compact` is for a panel inside a grid rather than a whole page: four
    full-height empty states stacked on a dashboard push real content off the
    screen.
--}}
@props([
    'icon' => 'box',
    'title' => null,
    'text' => null,
    'compact' => false,
])

<div @class(['empty-state', 'empty-state-compact' => $compact])>
    <span class="empty-state-icon">
        <x-nav-icon :name="$icon" :size="6"/>
    </span>

    <p class="empty-state-title">{{ $title ?? __('lang_v1.no_records_found') }}</p>

    @if ($text)
        <p class="empty-state-text">{{ $text }}</p>
    @endif

    @if (! $slot->isEmpty())
        <div class="mt-1 flex flex-wrap items-center justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
