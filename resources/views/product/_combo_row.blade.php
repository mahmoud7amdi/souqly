{{--
    One component of a combo product: a variation and how much of it one combo
    consumes.

    The row carries the picked variation's display name in a hidden input beside
    its id. It is submitted and ignored by the server — its only job is to survive
    a failed validation round-trip, because `old('combo')` gives back an id and a
    quantity, and re-rendering "#412 × 2" instead of "Coffee beans 250g × 2" would
    make the user re-pick every line to find out what they had chosen.
--}}
@php
    $index = $index ?? '__c__';
    $component = $component ?? null;

    $field = "combo[{$index}]";
@endphp

<tr data-combo-row>
    <td>
        <input type="hidden" name="{{ $field }}[variation_id]" value="{{ $component['variation_id'] ?? '' }}"
               data-combo-id>
        <input type="hidden" name="{{ $field }}[name]" value="{{ $component['name'] ?? '' }}" data-combo-label>

        <span class="cell-primary" data-combo-name>{{ $component['name'] ?? '' }}</span>
    </td>

    <td>
        <input name="{{ $field }}[quantity]" class="input-numeric w-28" inputmode="decimal"
               value="{{ $component['quantity'] ?? 1 }}"
               aria-label="{{ __('lang_v1.quantity') }}">
    </td>

    <td>
        <div class="cell-actions">
            <button type="button" class="btn-icon-danger" data-remove-combo
                    title="{{ __('lang_v1.remove') }}" aria-label="{{ __('lang_v1.remove') }}">
                <x-nav-icon name="x" :size="4"/>
            </button>
        </div>
    </td>
</tr>
