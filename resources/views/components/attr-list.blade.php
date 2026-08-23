{{--
    A record's own fields, as a definition list.

    Both `show` screens built the same array of label => value and then hand-rolled
    the same seven-line loop around it, so the loop lives here instead:

        <x-attr-list :columns="2" :items="[
            'lang_v1.sku'   => $product->sku,
            'lang_v1.brand' => $product->brand?->name,
        ]"/>

    Keys are translation keys, passed through __(). Values go through or_dash(),
    so a field that is null or an empty string renders a muted em-dash rather
    than a blank line — screens no longer need `?: '—'` on every entry, and the
    absent-value styling is the same on every screen by construction.

    `columns` is matched against a literal map, not interpolated: Tailwind scans
    source text for class names and cannot see one assembled at runtime.
--}}
@props(['items' => [], 'columns' => 1])

@php
    $grid = match ((int) $columns) {
        3 => 'sm:grid-cols-2 lg:grid-cols-3',
        2 => 'sm:grid-cols-2',
        default => '',
    };
@endphp

<dl {{ $attributes->class(['attr-grid', $grid]) }}>
    @foreach ($items as $key => $value)
        <div class="min-w-0">
            <dt class="attr-key">{{ __($key) }}</dt>
            <dd class="attr-value">{{ or_dash($value) }}</dd>
        </div>
    @endforeach
</dl>
