{{--
    A product's picture, at one of the design system's four thumbnail sizes.

    Every product image in the application goes through this component so that a
    catalogue with photos and a catalogue without look like the same screen. Two
    things it guarantees that a bare <img> does not:

    * A fixed box. `.thumb-*` is a square with `object-cover`, so a portrait
      photo, a wide banner and a missing file all occupy identical space and no
      table row or POS tile can be knocked out of alignment by whatever a
      supplier uploaded.

    * A designed absence. When there is no file, this draws a muted icon rather
      than the placeholder bitmap: a half-populated catalogue then reads as
      incomplete instead of as broken. Product::hasImage() is what tells the two
      apart — `image_url` alone cannot, because it never returns null.

    `loading="lazy"` matters more here than it looks: a 25-row product table is
    25 requests, and on a POS running over a shop's DSL line those compete with
    the fetch that is drawing the grid.
--}}
@props([
    'product',
    'size' => 'md',
    'alt' => null,
])

@php
    $frame = match ($size) {
        'sm' => 'thumb-sm',
        'lg' => 'thumb-lg',
        'tile' => 'thumb-tile',
        default => 'thumb-md',
    };

    // The icon scales with the frame; a size-4 glyph in a size-20 box reads as a
    // rendering error rather than as a placeholder.
    $iconSize = match ($size) {
        'sm' => 4,
        'lg' => 8,
        'tile' => 8,
        default => 5,
    };
@endphp

<span {{ $attributes->class([$frame]) }}>
    @if ($product->hasImage())
        <img src="{{ $product->image_url }}"
             alt="{{ $alt ?? $product->name }}"
             loading="lazy" decoding="async">
    @else
        <x-nav-icon name="box" :size="$iconSize"/>
    @endif
</span>
