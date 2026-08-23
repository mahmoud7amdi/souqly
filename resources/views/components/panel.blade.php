{{--
    Card with a titled header — the standard container for one block of related
    content inside a screen (a dues list, a stock alert list, a form section).

    The header is optional in appearance but not in structure: a card with no
    title is just a `card`, so use this only when the block needs naming. `count`
    renders as a badge beside the title, because "how many" is the first thing
    someone scanning a list of panels wants, and a number in the header saves
    them counting rows.

    `flush` drops the card's own body padding for panels whose content is a
    table — the table draws its own cell padding, and doubling it leaves the
    first column floating away from the card edge.

    The `footer` slot is the commit strip for a panel that *is* a form section:
    two forms side by side each need their own Save, so a page-level
    .form-actions cannot serve them. Without it every such screen hand-rolled
    `card-actions` plus a copy of this header's markup, and the copies drifted.

    `quiet` (design system v2.1) renders the block as `.surface-quiet` — grouped
    by tone alone, with no ring and no shadow. Use it for a block that belongs
    *with* the surrounding content rather than on top of it: a nested group
    inside a card, a set of related fields, an aside. A second white card inside
    a white card is the thing that makes a screen look like a template, and this
    is the way out of it. `quiet` and `flush` are mutually exclusive by nature —
    a tinted panel exists to hold padded content — so `flush` wins if both are
    passed, which keeps a table from losing its own padding.
--}}
@props([
    'title' => null,
    'subtitle' => null,
    'icon' => null,
    'count' => null,
    'flush' => false,
    'quiet' => false,
    'tone' => null,
])

@php
    /* Only a count worth acting on is coloured; zero is not news. */
    $badgeClass = match ($tone) {
        'danger' => 'badge-danger',
        'warning' => 'badge-warning',
        'success' => 'badge-success',
        default => 'badge-muted',
    };

    /* A named slot only exists once a caller supplies it. */
    $hasActions = isset($actions) && ! $actions->isEmpty();
    $hasFooter = isset($footer) && ! $footer->isEmpty();

    /* A quiet panel is a tinted region, not a raised surface, so it drops the
       card's own header padding too: `.surface-quiet` already carries p-5 and a
       second inset would leave the title floating. */
    $quiet = $quiet && ! $flush;
@endphp

<div {{ $attributes->class($quiet ? 'surface-quiet' : 'card') }}>
    @if ($title || $icon || $hasActions)
        <div class="{{ $quiet ? 'section-head' : 'card-header' }}">
            <div class="min-w-0">
                <h3 class="card-title flex min-w-0 items-center gap-2">
                    @if ($icon)
                        <x-nav-icon :name="$icon" :size="5"/>
                    @endif
                    <span class="truncate">{{ $title }}</span>
                    @if (! is_null($count) && $count > 0)
                        <span class="{{ $badgeClass }}">{{ $count }}</span>
                    @endif
                </h3>

                @if ($subtitle)
                    <p class="card-subtitle">{{ $subtitle }}</p>
                @endif
            </div>

            @if ($hasActions)
                <div class="toolbar no-print">{{ $actions }}</div>
            @endif
        </div>
    @endif


    @if ($flush)
        {{ $slot }}
    @elseif ($quiet)
        {{ $slot }}
    @else
        <div class="card-body">{{ $slot }}</div>
    @endif

    @if ($hasFooter)
        <div class="card-actions">{{ $footer }}</div>
    @endif
</div>
