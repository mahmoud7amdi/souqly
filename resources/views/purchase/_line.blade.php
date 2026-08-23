{{--
    One line of a purchase document — rendered from two places, deliberately.

    Server-side inside @foreach for the lines an edit form already has, and once
    more inside a <template> as the row the line editor clones when a product is
    picked. Until now the second copy was a JavaScript template string in
    _form.blade.php, and the two had already drifted: this file styled the product
    name with .cell-primary, the string literal spelled out `font-medium
    text-slate-900`, and the remove button used a different class in each. Every
    change to a row was two edits with no way to notice when only one was made.

    In template mode `$line` is null and `$index` is the literal '__i__', which the
    editor substitutes into every name attribute as it clones. Inputs inside a
    <template> are inert and never submitted, so the placeholder cannot reach the
    server even if the substitution were missed.
--}}
@php
    $line = $line ?? null;
    $lotTracking = $lotTracking ?? (bool) session('business.enable_lot_number');
    $expiryTracking = $expiryTracking ?? (bool) session('business.enable_product_expiry');
@endphp

<tr>
    <td>
        <input type="hidden" name="lines[{{ $index }}][variation_id]"
               value="{{ $line?->variation_id }}" data-variation>

        {{-- Only an existing line carries its own id; a cloned row must not send
             an empty one or the service would try to update line 0. --}}
        @if ($line)
            <input type="hidden" name="lines[{{ $index }}][purchase_line_id]" value="{{ $line->id }}">
        @endif

        <span class="cell-primary" data-name>{{ $line?->variations->full_name }}</span>
        <span class="cell-meta force-ltr" data-sku>{{ $line?->variations->sub_sku }}</span>
    </td>

    <td>
        <input name="lines[{{ $index }}][quantity]" data-qty class="input-numeric w-24"
               inputmode="decimal" value="{{ $line?->quantity ?? 1 }}"
               aria-label="{{ __('lang_v1.quantity') }}">
    </td>

    <td>
        <input name="lines[{{ $index }}][purchase_price]" data-cost class="input-numeric w-28"
               inputmode="decimal" value="{{ $line?->purchase_price ?? 0 }}"
               aria-label="{{ __('lang_v1.unit_cost') }}">
        <input type="hidden" name="lines[{{ $index }}][purchase_price_inc_tax]"
               value="{{ $line?->purchase_price_inc_tax ?? 0 }}" data-cost-inc>
    </td>

    @if ($lotTracking)
        <td>
            <input name="lines[{{ $index }}][lot_number]" class="input force-ltr"
                   value="{{ $line?->lot_number }}"
                   aria-label="{{ __('lang_v1.lot_number') }}">
        </td>
    @endif

    @if ($expiryTracking)
        <td>
            <input type="date" name="lines[{{ $index }}][exp_date]" class="input"
                   value="{{ $line?->exp_date?->toDateString() }}"
                   aria-label="{{ __('lang_v1.exp_date') }}">
        </td>
    @endif

    <td class="cell-numeric" data-subtotal>0</td>

    <td>
        <div class="cell-actions">
            <button type="button" class="btn-icon-danger" data-remove
                    title="{{ __('lang_v1.delete') }}"
                    aria-label="{{ __('lang_v1.delete') }}">
                <x-nav-icon name="trash" :size="4"/>
            </button>
        </div>
    </td>
</tr>
