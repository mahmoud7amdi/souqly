{{--
    One line of a stock transfer.

    Only ever rendered inside the <template> the editor clones — transfers have no
    edit screen (see StockTransferService for why), so there are no existing lines
    to render server-side. It stays a partial anyway so the row markup has one
    home, the way purchase._line does.

    No cost column: a transfer moves goods at whatever the consumed lots cost, and
    that is only knowable once the document is saved and FIFO has allocated. What
    the person needs while typing is the ceiling — how much the source shop
    actually has — because the service refuses a transfer that overdraws it.
--}}
@php $line = $line ?? null; @endphp

<tr>
    <td>
        <input type="hidden" name="lines[{{ $index }}][variation_id]"
               value="{{ $line?->variation_id }}" data-variation>

        <span class="cell-primary" data-name>{{ $line?->variations->full_name }}</span>
        <span class="cell-meta force-ltr" data-sku>{{ $line?->variations->sub_sku }}</span>
    </td>

    <td class="cell-numeric" data-available></td>

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
