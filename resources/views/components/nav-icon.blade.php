@props(['name' => 'dot', 'size' => 5])

@php
    /*
     | Single source of truth for every icon in the application.
     |
     | One file, one stroke weight, one 24×24 grid — so an icon means the same
     | thing on the sidebar, in a table row action and on a POS tile, and no
     | screen can introduce a stray glyph of its own. Paths are stroke-only
     | (fill="none") so they inherit `currentColor` and stay legible at 16px.
     |
     | Multi-subpath icons are written as one string; the renderer splits on
     | ' M' so each subpath gets its own <path> and joins/caps apply per shape.
     |
     | Glyphs that imply a direction (arrows, chevrons, undo) are listed in
     | $directional and get .icon-directional, which mirrors them under RTL —
     | an arrow that points "forward" must point left in Arabic.
     */
    $paths = [
        /* --- Navigation / modules ---------------------------------------- */
        'home' => 'M3 12l9-9 9 9M5 10v10h14V10',
        'pos' => 'M3 3h18v4H3zM5 7v14h14V7M9 11h6',
        'users' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
        'box' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        'plus' => 'M12 4v16m8-8H4',
        'minus' => 'M20 12H4',
        'tag' => 'M7 7h.01M7 3h5a2 2 0 011.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 10V5a2 2 0 012-2z',
        'folder' => 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',
        'star' => 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
        'scale' => 'M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3',
        'layers' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
        'shield' => 'M9 12l2 2 4-4M12 3l7 4v5c0 4.418-2.99 8.223-7 9-4.01-.777-7-4.582-7-9V7l7-4z',
        'percent' => 'M9 7h.01M15 17h.01M6 18L18 6',
        'barcode' => 'M4 4v16M8 4v16M12 4v16M16 4v16M20 4v16',
        'truck' => 'M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1',
        'clipboard' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
        'receipt' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        'document' => 'M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z',
        'undo' => 'M3 10h10a8 8 0 018 8v2M3 10l6 6M3 10l6-6',
        'transfer' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
        'adjust' => 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4',
        'cash' => 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z',
        'minus-circle' => 'M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z',
        'bank' => 'M4 10h16M4 10l8-6 8 6M6 10v8m4-8v8m4-8v8m4-8v8M4 20h16',
        'book' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253',
        'chart' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        'key' => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z',
        'store' => 'M3 3h18v4H3zM5 7v14h14V7M9 21v-6h6v6',
        'hash' => 'M7 20l4-16m2 16l4-16M6 9h14M4 15h14',
        'printer' => 'M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z',
        'mail' => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
        'cog' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
        'upload' => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12',
        'download' => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4',

        /* --- Row & toolbar actions ---------------------------------------
           These carry the day-to-day verbs. Kept visually distinct from each
           other at 16px, because table rows show three of them side by side. */
        'search' => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
        'filter' => 'M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V21l-4-2v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z',
        'edit' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
        'trash' => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
        'eye' => 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z',
        'check' => 'M5 13l4 4L19 7',
        'check-circle' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        'x' => 'M6 18L18 6M6 6l12 12',
        'x-circle' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
        /* Backspace, for the POS cash keypad. Deliberately absent from
           $directional: the field it deletes from is force-LTR, so the wedge has
           to keep pointing at the end the characters actually come off. */
        'backspace' => 'M11 10l4 4 M15 10l-4 4 M9 4h10a2 2 0 012 2v12a2 2 0 01-2 2H9L2 12z',
        'alert' => 'M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
        'info' => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'save' => 'M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4',
        'refresh' => 'M4 4v5h5M20 20v-5h-5M20 9A8 8 0 006.34 6.34L4 9m0 6a8 8 0 0013.66 2.66L20 15',
        'copy' => 'M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z',
        'external' => 'M14 5h5v5m0-5l-8 8M18 14v5a1 1 0 01-1 1H6a1 1 0 01-1-1V8a1 1 0 011-1h5',
        'more' => 'M6 12h.01M12 12h.01M18 12h.01',
        'menu' => 'M4 7h16M4 12h16M4 17h16',
        'list' => 'M4 6h.01M4 12h.01M4 18h.01M8 6h12M8 12h12M8 18h12',
        'grid' => 'M4 5a1 1 0 011-1h5v6H4V5zm10-1h5a1 1 0 011 1v5h-6V4zM4 14h6v6H5a1 1 0 01-1-1v-5zm10 0h6v5a1 1 0 01-1 1h-5v-6z',
        'sliders' => 'M4 6h9m4 0h3M4 12h3m4 0h9M4 18h9m4 0h3M13 4v4M7 10v4M13 16v4',

        /* --- Chevrons & arrows (all mirrored under RTL) ------------------- */
        'chevron-down' => 'M19 9l-7 7-7-7',
        'chevron-up' => 'M5 15l7-7 7 7',
        'chevron-forward' => 'M9 5l7 7-7 7',
        'chevron-back' => 'M15 19l-7-7 7-7',
        'arrow-forward' => 'M14 5l7 7-7 7M3 12h18',
        'arrow-back' => 'M10 19l-7-7 7-7M21 12H3',

        /* --- Sales, money & fulfilment ----------------------------------- */
        'cart' => 'M3 3h2l.4 2M7 13h10l3-8H5.4M7 13L5.4 5M7 13l-2 5h13M9 21a1 1 0 11-2 0 1 1 0 012 0zm10 0a1 1 0 11-2 0 1 1 0 012 0z',
        'card' => 'M3 10h18M3 8a2 2 0 012-2h14a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm4 7h4',
        'wallet' => 'M21 12V9a2 2 0 00-2-2H5a2 2 0 010-4h13v4M3 5v14a2 2 0 002 2h14a2 2 0 002-2v-3M17 14h.01',
        'coins' => 'M9 8a6 3 0 1112 0 6 3 0 01-12 0zm0 0v4c0 1.657 2.686 3 6 3s6-1.343 6-3V8M3 14a6 3 0 1112 0 6 3 0 01-12 0zm0 0v4c0 1.657 2.686 3 6 3s6-1.343 6-3v-4',
        'calculator' => 'M9 7h6m-6 4h.01M12 11h.01M15 11h.01M9 15h.01M12 15h.01M15 15h.01M7 3h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z',
        'discount' => 'M9 9h.01M15 15h.01M8 16L16 8M7 3h10a4 4 0 014 4v10a4 4 0 01-4 4H7a4 4 0 01-4-4V7a4 4 0 014-4z',
        'split' => 'M4 6h5l5 6h6m0 0l-3-3m3 3l-3 3M4 18h5l2-2.4',
        'package-out' => 'M20 7l-8-4-8 4v10l8 4 8-4V7zm-8 4l8-4m-8 4L4 7m8 4v10',

        /* --- People, time & place ---------------------------------------- */
        'user' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
        'user-plus' => 'M18 9v6m3-3h-6M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
        'calendar' => 'M8 7V3m8 4V3M4 11h16M5 7h14a1 1 0 011 1v11a1 1 0 01-1 1H5a1 1 0 01-1-1V8a1 1 0 011-1z',
        'clock' => 'M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z',
        'pin' => 'M15 11a3 3 0 11-6 0 3 3 0 016 0z M12 21s7-5.686 7-10a7 7 0 10-14 0c0 4.314 7 10 7 10z',
        'phone' => 'M3 5a2 2 0 012-2h2.28a1 1 0 01.95.68l1.2 3.6a1 1 0 01-.24 1.02l-1.5 1.5a12 12 0 005.51 5.51l1.5-1.5a1 1 0 011.02-.24l3.6 1.2a1 1 0 01.68.95V19a2 2 0 01-2 2h-1C9.61 21 3 14.39 3 6V5z',
        'globe' => 'M21 12a9 9 0 11-18 0 9 9 0 0118 0zM3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 010 18M12 3a15 15 0 000 18',
        'bell' => 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 11-6 0m6 0H9',
        'lock' => 'M8 11V7a4 4 0 118 0v4m-9 0h10a1 1 0 011 1v7a1 1 0 01-1 1H7a1 1 0 01-1-1v-7a1 1 0 011-1z',
        'logout' => 'M15 16l4-4m0 0l-4-4m4 4H9m3 8H6a2 2 0 01-2-2V6a2 2 0 012-2h6',
        /* Mirror of logout: the arrow enters the doorway rather than leaving it.
           Both are directional, so in Arabic the door swaps sides with it. */
        'login' => 'M9 16l4-4m0 0l-4-4m4 4H3 M14 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3',

        /* --- Connectivity (offline PWA banner) --------------------------- */
        'wifi' => 'M12 18h.01M8.5 14.5a5 5 0 017 0M5 11a10 10 0 0114 0M2 7.5a15 15 0 0120 0',
        'wifi-off' => 'M2 2l20 20M12 18h.01M8.5 14.5a5 5 0 016-.8M5 11a10 10 0 015.5-2.8M2 7.5A15 15 0 016 5.2m12.5 9.3A10 10 0 0016 11m2.5-3.4A15 15 0 0015 5.3',
        'cloud-off' => 'M3 3l18 18M7 17a4 4 0 01-.5-7.97A6 6 0 0117.6 8.4M20 15.5A3.5 3.5 0 0018 9h-.5M9 17h8',

        'dot' => 'M12 12h.01',
    ];

    /* Direction-bearing glyphs: mirrored by .icon-directional under RTL. */
    $directional = [
        'transfer', 'undo', 'upload', 'download', 'save', 'refresh', 'logout', 'login',
        'chevron-forward', 'chevron-back', 'arrow-forward', 'arrow-back',
        'external', 'split', 'search',
    ];

    $path = $paths[$name] ?? $paths['dot'];

    /* Literal class strings, not "size-{$size}": Tailwind scans source text
       for class names, so an interpolated utility is never generated. */
    $sizes = [4 => 'size-4', 5 => 'size-5', 6 => 'size-6', 8 => 'size-8', 10 => 'size-10'];
    $sizeClass = $sizes[(int) $size] ?? 'size-5';
@endphp

{{-- Attributes are forwarded rather than dropped, which is what let the header
     render both `wifi` and `wifi-off` and hide one of them. Until now every
     caller passed only `name` and `size`, so nothing relied on the old silent
     discard; `merge` keeps the size and shrink classes when a caller adds its
     own. --}}
<svg {{ $attributes->merge(['class' => $sizeClass.' shrink-0 '.(in_array($name, $directional, true) ? 'icon-directional' : '')]) }}
     fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
    @foreach (explode(' M', $path) as $index => $segment)
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
              d="{{ $index === 0 ? $segment : 'M'.$segment }}"/>
    @endforeach
</svg>
