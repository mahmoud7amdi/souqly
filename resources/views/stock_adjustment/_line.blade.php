{{--
    One line of a stock adjustment — rendered from two places, like
    purchase._line: server-side for the lines an edit form already has, and once
    inside a <template> as the row the editor clones when a product is picked.

    No money in this editor, deliberately. What a write-off is *worth* is the FIFO
    cost of the specific units it takes, and that is not knowable until the
    document is saved and the lots are allocated — so the columns here are the two
    facts a person can actually check while typing (what is on the shelf, and how
    much of it is gone) and the valuation appears on the document screen where it
    is a fact rather than a guess.
--}}
@php
    $line = $line ?? null;
    $locationId = $locationId ?? null;

    /* An existing line's units are already written off, so current stock does not
       include them — "available: 0" beside "quantity: 3" would read as an error.
       What the row means by available is what this document may take: what is on
       the shelf now, plus what this line already took. */
    $available = $line && $locationId
        ? $line->variation->currentStock($locationId) + (float) $line->quantity
        : null;
@endphp

<tr>
    <td>
        <input type="hidden" name="lines[{{ $index }}][variation_id]"
               value="{{ $line?->variation_id }}" data-variation>

        <span class="cell-primary" data-name>{{ $line?->variation->full_name }}</span>
        <span class="cell-meta force-ltr" data-sku>{{ $line?->variation->sub_sku }}</span>
    </td>

    <td class="cell-numeric" data-available>
        @if (! is_null($available))@format_quantity($available)@endif
    </td>

    <td>
        <input name="lines[{{ $index }}][quantity]" data-qty class="input-numeric w-24"
               inputmode="decimal" value="{{ $line?->quantity ?? 1 }}"
               aria-label="{{ __('lang_v1.quantity') }}">
    </td>

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
