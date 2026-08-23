{{--
    One line of a sell-side document — rendered from two places, deliberately.

    Server-side inside @foreach for the lines an edit form already has, and once
    more inside a <template> as the row the line editor clones when a product is
    picked. See purchase._line for why the row markup lives in one file: two
    copies of it drift, and nothing tells you when only one was changed.

    In template mode `$line` is null and `$index` is the literal '__i__', which the
    editor substitutes into every name attribute as it clones. Inputs inside a
    <template> are inert and never submitted, so the placeholder cannot reach the
    server even if the substitution were missed.

    No per-line discount column, unlike the schema's line_discount_* pair:
    SellService::recalculateTotals() sums quantity × unit_price and never applies
    a line discount, so an input for one would change a number on screen that the
    saved document ignores. The price shown here IS the price charged; a discount
    is expressed at the document level, where it does move the total.
--}}
@php
    $line = $line ?? null;
@endphp

<tr>
    <td>
        <input type="hidden" name="lines[{{ $index }}][variation_id]"
               value="{{ $line?->variation_id }}" data-variation>

        {{-- Carried through an edit so the sales order this line fulfils keeps
             its outstanding quantity; blank on a walk-up line. --}}
        <input type="hidden" name="lines[{{ $index }}][so_line_id]"
               value="{{ $line?->so_line_id }}" data-so-line>

        <span class="cell-primary" data-name>{{ $line?->variations->full_name }}</span>
        <span class="cell-meta force-ltr" data-sku>{{ $line?->variations->sub_sku }}</span>
    </td>

    <td>
        <input name="lines[{{ $index }}][quantity]" data-qty class="input-numeric w-24"
               inputmode="decimal" value="{{ $line?->quantity ?? 1 }}"
               aria-label="{{ __('lang_v1.quantity') }}">
    </td>

    <td>
        <input name="lines[{{ $index }}][unit_price]" data-price class="input-numeric w-28"
               inputmode="decimal" value="{{ $line?->unit_price ?? 0 }}"
               aria-label="{{ __('lang_v1.unit_price') }}">
    </td>

    <td>
        <input name="lines[{{ $index }}][sell_line_note]" class="input"
               value="{{ $line?->sell_line_note }}"
               placeholder="—" aria-label="{{ __('lang_v1.notes') }}">
    </td>

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
