@props(['status'])
@php
    /* Status is shown as colour + glyph. The glyph is what a cashier actually
       reads at speed; the colour only reinforces it. Kept in one component so
       every listing, detail screen and receipt agrees on the vocabulary. */
    $map = [
        'received'   => ['badge-success', 'check-circle',  __('lang_v1.received')],
        'pending'    => ['badge-warning', 'clock',         __('lang_v1.pending')],
        'ordered'    => ['badge-info',    'clipboard',     __('lang_v1.ordered')],
        'partial'    => ['badge-warning', 'split',         __('lang_v1.partial')],
        'completed'  => ['badge-success', 'check-circle',  __('lang_v1.completed')],
        'final'      => ['badge-success', 'check',         __('lang_v1.final')],
        'draft'      => ['badge-muted',   'document',      __('lang_v1.draft')],
        'in_transit' => ['badge-info',    'truck',         __('lang_v1.in_transit')],
        'packed'     => ['badge-info',    'box',           __('lang_v1.packed')],
        'shipped'    => ['badge-info',    'truck',         __('lang_v1.shipped')],
        'delivered'  => ['badge-success', 'check-circle',  __('lang_v1.delivered')],
        'cancelled'  => ['badge-danger',  'x-circle',      __('lang_v1.cancelled')],
    ];
    [$class, $icon, $label] = $map[$status] ?? ['badge-muted', 'dot', $status];
@endphp
<span class="{{ $class }}">
    <x-nav-icon :name="$icon" :size="4"/>
    {{ $label }}
</span>
