{{--
    Product form, shared by create and edit.

    Expects: $product (null on create), plus the dropdown data from
    ProductController::formData().
--}}
@php
    $product = $product ?? null;
    $isEdit = ! is_null($product);
    $variation = $isEdit ? $product->variations->first() : null;

    /* An edited variable product prices each variation in the table that
       product/edit renders below this form, and ProductController::update
       ignores single_dsp for anything but a single product — so showing the
       single-price panel here would offer three inputs that are read by nobody
       and contradict the authoritative figures underneath. */
    $showSinglePricing = ! ($isEdit && $product->type === 'variable');
@endphp

<div class="grid gap-6 lg:grid-cols-3">

    <x-panel :title="__('lang_v1.product_details')" icon="box" class="lg:col-span-2">
        <div class="form-grid">
            <div class="field sm:col-span-2">
                <label for="name" class="label label-required">{{ __('lang_v1.name') }}</label>
                <input id="name" name="name" @class(['input', 'input-invalid' => $errors->has('name')])
                       value="{{ old('name', $product->name ?? '') }}" required>
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="sku" class="label">{{ __('lang_v1.sku') }}</label>
                <input id="sku" name="sku" class="input force-ltr"
                       value="{{ old('sku', $product->sku ?? ($suggestedSku ?? '')) }}">
                <p class="hint">{{ __('lang_v1.sku_auto_hint') }}</p>
            </div>

            <div class="field">
                <label for="barcode_type" class="label label-required">{{ __('lang_v1.barcode_type') }}</label>
                <select id="barcode_type" name="barcode_type" class="select">
                    @foreach ($barcodeTypes as $value => $name)
                        <option value="{{ $value }}"
                            @selected(old('barcode_type', $product->barcode_type ?? 'C128') === $value)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="type" class="label label-required">{{ __('lang_v1.type') }}</label>
                <select id="type" name="type" class="select" @disabled($isEdit)>
                    @foreach ($types as $value => $name)
                        <option value="{{ $value }}" @selected(old('type', $product->type ?? 'single') === $value)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                @if ($isEdit)
                    {{-- Immutable after creation: switching type would orphan
                         the FIFO lots attached to the existing variations. --}}
                    <input type="hidden" name="type" value="{{ $product->type }}">
                    <p class="hint">{{ __('lang_v1.type_immutable_hint') }}</p>
                @endif
            </div>

            <div class="field">
                <label for="unit_id" class="label label-required">{{ __('lang_v1.unit') }}</label>
                <select id="unit_id" name="unit_id"
                        @class(['select', 'input-invalid' => $errors->has('unit_id')]) required>
                    @foreach ($units as $id => $name)
                        <option value="{{ $id }}" @selected(old('unit_id', $product->unit_id ?? '') == $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                @error('unit_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="brand_id" class="label">{{ __('lang_v1.brand') }}</label>
                <select id="brand_id" name="brand_id" class="select">
                    @foreach ($brands as $id => $name)
                        <option value="{{ $id }}" @selected(old('brand_id', $product->brand_id ?? '') == $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="category_id" class="label">{{ __('lang_v1.category') }}</label>
                <select id="category_id" name="category_id" class="select">
                    @foreach ($categories as $id => $name)
                        <option value="{{ $id }}" @selected(old('category_id', $product->category_id ?? '') == $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="warranty_id" class="label">{{ __('lang_v1.warranty') }}</label>
                <select id="warranty_id" name="warranty_id" class="select">
                    @foreach ($warranties as $id => $name)
                        <option value="{{ $id }}" @selected(old('warranty_id', $product->warranty_id ?? '') == $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field sm:col-span-2">
                <label for="product_description" class="label">{{ __('lang_v1.description') }}</label>
                <textarea id="product_description" name="product_description" rows="3"
                          class="textarea">{{ old('product_description', $product->product_description ?? '') }}</textarea>
            </div>
        </div>
    </x-panel>

    {{-- Tax, stock and pricing --}}
    <div class="grid gap-6 self-start">

        <x-panel :title="__('lang_v1.tax')" icon="percent">
            <div class="grid gap-4">
                <div class="field">
                    <label for="tax" class="label">{{ __('lang_v1.tax_rate') }}</label>
                    <select id="tax" name="tax" class="select">
                        @foreach ($taxes as $id => $name)
                            <option value="{{ $id }}" @selected(old('tax', $product->tax ?? '') == $id)>
                                {{ $name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="tax_type" class="label label-required">{{ __('lang_v1.tax_type') }}</label>
                    <select id="tax_type" name="tax_type" class="select">
                        <option value="exclusive"
                            @selected(old('tax_type', $product->tax_type ?? 'exclusive') === 'exclusive')>
                            {{ __('lang_v1.exclusive') }}
                        </option>
                        <option value="inclusive"
                            @selected(old('tax_type', $product->tax_type ?? '') === 'inclusive')>
                            {{ __('lang_v1.inclusive') }}
                        </option>
                    </select>
                </div>
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.stock')" icon="layers">
            <div class="grid gap-4">
                <label class="checkbox-row">
                    <input type="checkbox" name="enable_stock" value="1" class="checkbox"
                           @checked(old('enable_stock', $product->enable_stock ?? true))>
                    <span class="checkbox-label">{{ __('lang_v1.manage_stock') }}</span>
                </label>

                <div class="field">
                    <label for="alert_quantity" class="label">{{ __('lang_v1.alert_quantity') }}</label>
                    <input id="alert_quantity" name="alert_quantity" class="input-numeric"
                           inputmode="decimal" value="{{ old('alert_quantity', $product->alert_quantity ?? 0) }}">
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="not_for_selling" value="1" class="checkbox"
                           @checked(old('not_for_selling', $product->not_for_selling ?? false))>
                    <span class="checkbox-label">{{ __('lang_v1.not_for_selling') }}</span>
                </label>
            </div>
        </x-panel>

        @if ($showSinglePricing)
            <x-panel :title="__('lang_v1.pricing')" icon="coins">
                <div class="grid gap-4">
                    <div class="field">
                        <label for="single_dpp" class="label">{{ __('lang_v1.purchase_price') }}</label>
                        <input id="single_dpp" name="single_dpp" class="input-numeric" inputmode="decimal"
                               value="{{ old('single_dpp', $variation->default_purchase_price ?? '') }}">
                    </div>

                    <div class="field">
                        <label for="profit_percent" class="label">{{ __('lang_v1.profit_percent') }}</label>
                        <input id="profit_percent" name="profit_percent" class="input-numeric" inputmode="decimal"
                               value="{{ old('profit_percent', $variation->profit_percent ?? session('business.default_profit_percent', 0)) }}">
                    </div>

                    <div class="field">
                        <label for="single_dsp" class="label">{{ __('lang_v1.sell_price') }}</label>
                        <input id="single_dsp" name="single_dsp" class="input-numeric" inputmode="decimal"
                               value="{{ old('single_dsp', $variation->default_sell_price ?? '') }}">
                        <p class="hint">{{ __('lang_v1.price_derivation_hint') }}</p>
                    </div>
                </div>
            </x-panel>
        @endif
    </div>
</div>

{{-- Location availability. No selection = available everywhere. --}}
@if (count($locations) > 1)
    @php $selected = old('location_ids', $isEdit ? $product->product_locations->pluck('id')->all() : []); @endphp

    <x-panel :title="__('lang_v1.available_at')" icon="store" class="mt-6">
        <x-slot:actions>
            <span class="hint mt-0">{{ __('lang_v1.locations_empty_means_all') }}</span>
        </x-slot:actions>

        <div class="grid gap-2 sm:grid-cols-3">
            @foreach ($locations as $id => $name)
                <label class="checkbox-row">
                    <input type="checkbox" name="location_ids[]" value="{{ $id }}" class="checkbox"
                           @checked(in_array($id, (array) $selected))>
                    <span class="checkbox-label">{{ $name }}</span>
                </label>
            @endforeach
        </div>
    </x-panel>
@endif
