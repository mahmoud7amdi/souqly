{{--
    One value of a variation group — "S", "Red" — with its three prices.

    Prices sit per value rather than per group because that is where they actually
    differ: an XL shirt costs more than an S, and pricing the group would make the
    difference unrepresentable. ProductService::normalisePrices() fills in whichever
    of the three is left blank, so a row with only a sell price is complete.
--}}
@php
    $groupIndex = $groupIndex ?? '__g__';
    $valueIndex = $valueIndex ?? '__v__';
    $value = $value ?? null;

    $field = "variations[{$groupIndex}][variations][{$valueIndex}]";
    $rowLabel = $value['name'] ?? __('lang_v1.variation_value');
@endphp

<tr data-value>
    <td>
        <input name="{{ $field }}[name]" class="input" data-value-name
               value="{{ $value['name'] ?? '' }}"
               placeholder="{{ __('lang_v1.variation_value_placeholder') }}"
               aria-label="{{ __('lang_v1.variation_value') }}" maxlength="255">
    </td>

    <td>
        <input name="{{ $field }}[dpp]" class="input-numeric w-32" inputmode="decimal"
               value="{{ $value['dpp'] ?? '' }}"
               aria-label="{{ $rowLabel.' — '.__('lang_v1.purchase_price') }}">
    </td>

    <td>
        <input name="{{ $field }}[profit_percent]" class="input-numeric w-28" inputmode="decimal"
               value="{{ $value['profit_percent'] ?? '' }}"
               aria-label="{{ $rowLabel.' — '.__('lang_v1.profit_percent') }}">
    </td>

    <td>
        <input name="{{ $field }}[dsp]" class="input-numeric w-32" inputmode="decimal"
               value="{{ $value['dsp'] ?? '' }}"
               aria-label="{{ $rowLabel.' — '.__('lang_v1.sell_price') }}">
    </td>

    <td>
        <div class="cell-actions">
            <button type="button" class="btn-icon-danger" data-remove-value
                    title="{{ __('lang_v1.remove') }}" aria-label="{{ __('lang_v1.remove') }}">
                <x-nav-icon name="x" :size="4"/>
            </button>
        </div>
    </td>
</tr>
