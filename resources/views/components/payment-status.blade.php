@props(['status'])
@php
    /* Money owed is the single most-scanned fact in the app, so it gets a
       glyph as well as a colour — see components/transaction-status. */
    $map = [
        'paid'      => ['badge-success', 'check-circle', __('lang_v1.paid')],
        'partial'   => ['badge-warning', 'split',        __('lang_v1.partial')],
        'due'       => ['badge-danger',  'alert',        __('lang_v1.due')],
        'overdue'   => ['badge-danger',  'clock',        __('lang_v1.overdue')],
    ];
    [$class, $icon, $label] = $map[$status] ?? ['badge-muted', 'dot', $status];
@endphp
<span class="{{ $class }}">
    <x-nav-icon :name="$icon" :size="4"/>
    {{ $label }}
</span>
