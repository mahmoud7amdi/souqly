{{--
    One variation group: an attribute (Size, Colour) and its values.

    Rendered twice — once for real rows, once inside the <template> that clones a
    new group — so the markup can only drift from itself if someone edits it here,
    which is the point. `$index` is `__g__` in the template copy and a real integer
    otherwise, and every `name` carries it so the whole thing arrives as
    `variations[0][variations][2][name]` without any JS having to know the shape.

    The value rows are the same trick one level down, with `__v__`.
--}}
@php
    $index = $index ?? '__g__';
    $group = $group ?? null;
    $values = $group['variations'] ?? [null];
@endphp

<div class="variation-group" data-group data-group-index="{{ $index }}">
    <div class="variation-group-head">
        <p class="variation-group-title">
            <x-nav-icon name="layers" :size="4"/>
            {{-- Echoes the name input as it is typed. With four groups open, "Size"
                 and "Colour" in the header is the difference between reading the
                 screen and counting tables. --}}
            <span data-group-title>{{ $group['name'] ?? __('lang_v1.variation_group') }}</span>
        </p>

        <button type="button" class="btn-icon-danger" data-remove-group
                title="{{ __('lang_v1.remove') }}" aria-label="{{ __('lang_v1.remove') }}">
            <x-nav-icon name="trash" :size="4"/>
        </button>
    </div>

    <div class="variation-group-body">
        <div class="form-grid">
            <div class="field">
                <label class="label">{{ __('lang_v1.variation_template') }}</label>
                <select name="variations[{{ $index }}][template_id]" class="select" data-template>
                    <option value="">{{ __('lang_v1.custom_variation') }}</option>
                    @foreach ($variationTemplates as $id => $name)
                        <option value="{{ $id }}" @selected(($group['template_id'] ?? '') == $id)>{{ $name }}</option>
                    @endforeach
                </select>
                {{-- The template is a starting point, not a binding choice: it fills
                     the rows below and then gets out of the way, because a shop that
                     keeps "Size" as S/M/L but needs one XL this once should not have
                     to edit the template to sell the shirt. --}}
                <p class="hint">{{ __('lang_v1.variation_template_hint') }}</p>
            </div>

            <div class="field">
                <label class="label label-required">{{ __('lang_v1.variation_name') }}</label>
                <input name="variations[{{ $index }}][name]" class="input" data-group-name
                       value="{{ $group['name'] ?? '' }}"
                       placeholder="{{ __('lang_v1.variation_name_placeholder') }}" maxlength="255">
            </div>
        </div>

        <div class="table-wrap mt-4">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.variation_value') }}</th>
                        <th class="th-numeric w-36">{{ __('lang_v1.purchase_price') }}</th>
                        <th class="th-numeric w-32">{{ __('lang_v1.profit_percent') }}</th>
                        <th class="th-numeric w-36">{{ __('lang_v1.sell_price') }}</th>
                        <th class="w-12"><span class="sr-only">{{ __('lang_v1.actions') }}</span></th>
                    </tr>
                </thead>

                <tbody data-values>
                    @foreach ($values as $valueIndex => $value)
                        @include('product._variation_value', [
                            'groupIndex' => $index,
                            'valueIndex' => is_int($valueIndex) ? $valueIndex : 0,
                            'value' => $value,
                        ])
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="variation-group-foot">
            <button type="button" class="btn-secondary btn-sm" data-add-value>
                <x-nav-icon name="plus" :size="4"/>
                {{ __('lang_v1.add_variation_value') }}
            </button>
        </div>
    </div>
</div>
