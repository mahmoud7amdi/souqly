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

    /* Which of the three type-dependent blocks below is open on first paint.
       Computed here rather than left to the toggle script, so a validation
       bounce comes back showing the same sections the user was filling in — and
       so the form is still usable if the script never runs. */
    $currentType = old('type', $product->type ?? 'single');
@endphp

{{-- What the product *is*: its identity, and how it is taxed, stocked and priced.
     One section, so the availability group below reads as a separate question. --}}
<div class="section grid gap-6 lg:grid-cols-3">

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
            {{-- One price for the whole product, so it is meaningless for a variable
                 one — each value carries its own in the section below. A combo keeps
                 it: a bundle is sold at its own price, not at the sum of its parts.
                 The wrapper exists so the toggle has something to hide that is also
                 the grid item; hiding the card itself would leave its gap behind. --}}
            <div id="single-pricing" @if ($currentType === 'variable') hidden @endif>
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
            </div>
        @endif
    </div>
</div>

{{-- ==================================================================
     Variable products: the attribute groups and the values inside them.

     Hidden rather than absent, and hidden from the server-rendered `$currentType`
     rather than by the script on load — so a validation bounce comes back with the
     same sections open, and so the form still works if the script never runs. The
     script below only handles live switching of the type select.
     ================================================================== --}}
@php
    /* On create, one empty group is offered: the shape of the thing should be
       visible without a click. On edit the container starts empty, because the
       groups that already exist are priced in product/edit's own table and
       anything typed here is an addition to them, not a replacement.

       `?:` and not a default argument, because a bounce that pruned every row
       leaves `variations` present but empty — and an empty container with no
       group in it is the bug this section exists to fix. */
    $newGroups = old('variations') ?: ($isEdit ? [] : [null]);

    /* Nested rules produce keys like `variations.0.variations.1.name`, which
       @error('variations') never sees. The messages are collected here instead —
       a group two screens down with an empty value is exactly the error that is
       easiest to miss. */
    $variationErrors = collect($errors->getMessages())
        ->filter(fn ($messages, $key) => str_starts_with($key, 'variations'))
        ->flatten()->unique()->values();
@endphp

<div id="variations-section" class="section" @if ($currentType !== 'variable') hidden @endif>
    <div class="section-head">
        <div class="section-head-text">
            <p class="section-eyebrow">{{ __('lang_v1.variable') }}</p>
            <h2 class="section-title">
                {{ $isEdit ? __('lang_v1.add_variations') : __('lang_v1.variations') }}
            </h2>
            <p class="section-desc">
                {{ $isEdit ? __('lang_v1.add_variations_desc') : __('lang_v1.variations_desc') }}
            </p>
        </div>
    </div>

    @if ($variationErrors->isNotEmpty())
        <div class="alert-danger mb-4" role="alert">
            <x-nav-icon name="alert"/>
            <div class="min-w-0">
                @foreach ($variationErrors as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid gap-4" data-groups>
        @foreach ($newGroups as $groupIndex => $group)
            @include('product._variation_group', ['index' => (int) $groupIndex, 'group' => $group])
        @endforeach
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <button type="button" class="btn-secondary" data-add-group>
            <x-nav-icon name="plus" :size="4"/>
            {{ __('lang_v1.add_variation_group') }}
        </button>
        <p class="hint">{{ __('lang_v1.variations_sub_sku_hint') }}</p>
    </div>
</div>

{{-- ==================================================================
     Combo products: which variations one bundle consumes.

     Create only. `ProductController::update()` deliberately never rebuilds a
     combo's component list, so offering the editor on edit would be three inputs
     read by nobody — the same trap the single-price panel avoids above.
     ================================================================== --}}
@if (! $isEdit)
    @php
        $comboRows = old('combo', []);

        $comboErrors = collect($errors->getMessages())
            ->filter(fn ($messages, $key) => str_starts_with($key, 'combo'))
            ->flatten()->unique()->values();
    @endphp

    <div id="combo-section" class="section" @if ($currentType !== 'combo') hidden @endif>
        <div class="section-head">
            <div class="section-head-text">
                <p class="section-eyebrow">{{ __('lang_v1.combo') }}</p>
                <h2 class="section-title">{{ __('lang_v1.combo_components') }}</h2>
                <p class="section-desc">{{ __('lang_v1.combo_components_desc') }}</p>
            </div>

            <div class="section-actions">
                <div class="input-search-wrap">
                    <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                    <input id="combo-search" class="input-search w-72"
                           placeholder="{{ __('lang_v1.search_product_to_add') }}"
                           autocomplete="off" aria-label="{{ __('lang_v1.search_product_to_add') }}">
                </div>
                <button type="button" id="combo-add" class="btn-secondary">
                    <x-nav-icon name="plus" :size="4"/>
                    {{ __('lang_v1.add') }}
                </button>
            </div>
        </div>

        @if ($comboErrors->isNotEmpty())
            <div class="alert-danger mb-4" role="alert">
                <x-nav-icon name="alert"/>
                <div class="min-w-0">
                    @foreach ($comboErrors as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            </div>
        @endif

        <x-panel flush>
            <div class="table-wrap table-flush">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('lang_v1.product') }}</th>
                            <th class="th-numeric w-32">{{ __('lang_v1.quantity') }}</th>
                            <th class="w-12"><span class="sr-only">{{ __('lang_v1.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody id="combo-body">
                        @foreach ($comboRows as $comboIndex => $component)
                            @include('product._combo_row', [
                                'index' => (int) $comboIndex,
                                'component' => $component,
                            ])
                        @endforeach
                    </tbody>
                </table>

                {{-- Its own row rather than <x-table-empty>, because it has to be
                     hideable by the script the moment the first component lands. --}}
                <table class="table" id="combo-empty" @if (count($comboRows)) hidden @endif>
                    <tbody>
                        <x-table-empty :columns="3" icon="box"
                                       :title="__('lang_v1.no_components_yet')"
                                       :text="__('lang_v1.no_components_yet_desc')"/>
                    </tbody>
                </table>
            </div>
        </x-panel>
    </div>
@endif

{{-- Clone sources. Content inside <template> is inert, so the `__g__`/`__v__`/
     `__c__` placeholders never reach the server. --}}
<template id="group-template">
    @include('product._variation_group', ['index' => '__g__', 'group' => null])
</template>

<template id="value-template">
    @include('product._variation_value', ['groupIndex' => '__g__', 'valueIndex' => '__v__', 'value' => null])
</template>

@if (! $isEdit)
    <template id="combo-template">
        @include('product._combo_row', ['index' => '__c__', 'component' => null])
    </template>
@endif

@if (count($locations) > 1)
    @php $selected = old('location_ids', $isEdit ? $product->product_locations->pluck('id')->all() : []); @endphp

    {{-- Where the product may be sold, which is a different question from what it
         is — so it gets a section head rather than a fourth card in the grid above.
         The "no selection means all" rule moves into the section description: it is
         the one thing to know *before* ticking anything, and a hint tucked into a
         card's action slot is precisely where it went unread. --}}
    <div class="section-head">
        <div class="section-head-text">
            <p class="section-eyebrow">{{ __('lang_v1.availability') }}</p>
            <h2 class="section-title">{{ __('lang_v1.available_at') }}</h2>
            <p class="section-desc">{{ __('lang_v1.locations_empty_means_all') }}</p>
        </div>
    </div>

    {{-- `.section` here and not on the caller: product/edit follows this partial
         with its variation-price section, and `.section-head` carries no top
         margin of its own, so the gutter has to come from the block above it. --}}
    <x-panel class="section">
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

{{-- The half of this form that depends on `type`.

     Three jobs. The first is the whole reason the sections above exist: choosing
     «Variable» has to reveal the variations editor *now*, not after a save that
     fails. The other two are the row editors — clone a group, clone a value — and
     the combo picker.

     None of it is load-bearing for correctness. `$currentType` already opened the
     right sections server-side, so a browser that never runs this still shows a
     usable form; what it loses is the live toggle, the second row and the
     template auto-fill. --}}
@push('scripts')
<script>
(function () {
    /* ================================================================
       1 — Which sections are open
       ================================================================ */

    const typeSelect = document.getElementById('type');
    const variationsSection = document.getElementById('variations-section');
    const comboSection = document.getElementById('combo-section');
    const singlePricing = document.getElementById('single-pricing');

    const applyType = function () {
        const type = typeSelect ? typeSelect.value : @json($currentType);

        if (variationsSection) variationsSection.hidden = type !== 'variable';
        if (comboSection) comboSection.hidden = type !== 'combo';

        /* A combo keeps the single-price panel — a bundle is sold at its own
           price, not at the sum of its parts. Only a variable product prices per
           value, and leaving both open would offer two answers to one question.
           The panel is absent entirely when editing a variable product, hence
           the guard rather than a bare assignment. */
        if (singlePricing) singlePricing.hidden = type === 'variable';
    };

    /* Editing never fires this: the select is disabled there because switching
       type would orphan the FIFO lots hanging off the existing variations, and
       the server has already rendered the right sections open. */
    typeSelect?.addEventListener('change', applyType);
    applyType();

    /* ================================================================
       2 — Variation groups and their values
       ================================================================ */

    const groups = document.querySelector('[data-groups]');
    const groupTemplate = document.getElementById('group-template');
    const valueTemplate = document.getElementById('value-template');
    const fallbackTitle = @json(__('lang_v1.variation_group'));

    /* Indices only ever climb, and that is not fastidiousness: `variations[1]`
       handed to a new group after group 1 was removed would merge with any rows
       still carrying it once PHP rebuilds the array, and the merge is silent. */
    let nextGroup = groups ? groups.querySelectorAll('[data-group]').length : 0;

    const stamp = function (root, from, to) {
        root.querySelectorAll('[name]').forEach(function (field) {
            field.name = field.name.replaceAll(from, to);
        });
    };

    const syncTitle = function (group) {
        const input = group.querySelector('[data-group-name]');
        const title = group.querySelector('[data-group-title]');

        /* With four groups open, «Size» and «Colour» in the headers is the
           difference between reading the screen and counting tables. */
        if (title) title.textContent = input?.value.trim() || fallbackTitle;
    };

    /* Read the highest index in use rather than counting rows. Delete the middle
       row of three and the count says 2 — an index the last row already owns,
       and PHP keeps one of the two values without saying which. */
    const nextValue = function (group) {
        const used = [...group.querySelectorAll('[data-value] [data-value-name]')]
            .map(function (field) {
                const found = field.name.match(/\[variations]\[(\d+)]/);

                return found ? parseInt(found[1], 10) : -1;
            });

        return used.length ? Math.max(...used) + 1 : 0;
    };

    const addValue = function (group, name) {
        const fragment = valueTemplate.content.cloneNode(true);
        const row = fragment.querySelector('[data-value]');

        stamp(row, '__g__', group.dataset.groupIndex);
        stamp(row, '__v__', nextValue(group));

        if (name) row.querySelector('[data-value-name]').value = name;

        group.querySelector('[data-values]').appendChild(fragment);

        return row;
    };

    const addGroup = function () {
        const fragment = groupTemplate.content.cloneNode(true);
        const group = fragment.querySelector('[data-group]');
        const index = nextGroup++;

        /* The template already carries one value row at index 0, so `__g__` is
           the only placeholder in it — and `data-group-index` needs the same
           substitution as the field names, because addValue() reads it back. */
        stamp(group, '__g__', index);
        group.dataset.groupIndex = index;

        groups.appendChild(fragment);
        syncTitle(group);

        return group;
    };

    document.querySelector('[data-add-group]')?.addEventListener('click', addGroup);

    groups?.addEventListener('input', function (event) {
        if (event.target.matches('[data-group-name]')) {
            syncTitle(event.target.closest('[data-group]'));
        }
    });

    groups?.addEventListener('click', function (event) {
        const dropGroup = event.target.closest('[data-remove-group]');

        if (dropGroup) {
            dropGroup.closest('[data-group]').remove();

            /* Never leave the container empty. An empty variations section on a
               variable product is the exact defect this section was added to
               fix, and the server rejects it anyway. */
            if (! groups.querySelector('[data-group]')) addGroup();

            return;
        }

        const dropValue = event.target.closest('[data-remove-value]');

        if (dropValue) {
            const group = dropValue.closest('[data-group]');

            dropValue.closest('[data-value]').remove();

            // Same rule one level down: a group with no values is not a group.
            if (! group.querySelector('[data-value]')) addValue(group);

            return;
        }

        const add = event.target.closest('[data-add-value]');

        if (add) addValue(add.closest('[data-group]'));
    });

    /* A template is a starting point, not a binding choice: it fills the rows and
       gets out of the way. `request()` in resources/js/app.js is module-local and
       not on `window`, so the token comes from the meta tag directly. */
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    groups?.addEventListener('change', async function (event) {
        const select = event.target.closest('[data-template]');

        if (! select?.value) return;

        const group = select.closest('[data-group]');

        const response = await fetch(@json(route('products.variationTemplate')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            body: JSON.stringify({ template_id: select.value }),
        });

        if (! response.ok) return;

        const template = await response.json();
        const nameField = group.querySelector('[data-group-name]');
        const body = group.querySelector('[data-values]');

        /* Only fill a name nobody has written. Picking «Size» to get S/M/L must
           not rename an attribute the user deliberately called «مقاس». */
        if (! nameField.value.trim()) {
            nameField.value = template.name ?? '';
            syncTitle(group);
        }

        /* Typed rows survive; the blank ones are what the template was asked to
           fill. A shop keeping Size as S/M/L but needing one XL this once should
           not have to edit the template to sell the shirt. */
        const kept = [...body.querySelectorAll('[data-value-name]')]
            .map(field => field.value.trim())
            .filter(Boolean);

        body.querySelectorAll('[data-value]').forEach(function (row) {
            if (! row.querySelector('[data-value-name]').value.trim()) row.remove();
        });

        (template.values ?? []).forEach(function (value) {
            if (! kept.includes(value)) addValue(group, value);
        });

        if (! body.querySelector('[data-value]')) addValue(group);
    });

    /* ================================================================
       3 — Combo components
       ================================================================ */

    const comboBody = document.getElementById('combo-body');
    const comboTemplate = document.getElementById('combo-template');

    // Both absent when editing: a combo's parts are changed from the edit screen's
    // own section, not rebuilt from a picker.
    if (! comboBody || ! comboTemplate) return;

    const comboEmpty = document.getElementById('combo-empty');
    const comboSearch = document.getElementById('combo-search');
    const comboAdd = document.getElementById('combo-add');

    let nextComponent = comboBody.querySelectorAll('[data-combo-row]').length;
    let picked = null;

    const refresh = function () {
        comboEmpty.hidden = comboBody.querySelectorAll('[data-combo-row]').length > 0;
    };

    const addComponent = function (product) {
        /* The same variation twice posts two rows the service adds up separately,
           which is not what picking it twice meant. */
        const seen = [...comboBody.querySelectorAll('[data-combo-id]')]
            .find(field => field.value === String(product.variation_id));

        if (seen) {
            const qty = seen.closest('[data-combo-row]').querySelector('[name$="[quantity]"]');

            qty.value = (parseFloat(qty.value) || 0) + 1;

            return;
        }

        const fragment = comboTemplate.content.cloneNode(true);
        const row = fragment.querySelector('[data-combo-row]');

        stamp(row, '__c__', nextComponent++);

        row.querySelector('[data-combo-id]').value = product.variation_id;
        row.querySelector('[data-combo-label]').value = product.text;
        row.querySelector('[data-combo-name]').textContent = product.text;

        comboBody.appendChild(fragment);
        refresh();
    };

    /* The picker the purchase and sell screens already use, against the same
       endpoint. No location filter: this form has no location select — it has
       checkboxes for availability — and `getProducts` treats it as optional. */
    let timer = null;

    comboSearch.addEventListener('input', function () {
        clearTimeout(timer);

        const term = comboSearch.value.trim();

        if (term.length < 2) {
            picked = null;

            return;
        }

        timer = setTimeout(async function () {
            const params = new URLSearchParams({ term: term });

            const response = await fetch(@json(route('products.list')) + '?' + params, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (! response.ok) return;

            const results = await response.json();

            picked = results[0] ?? null;

            // Exactly one hit on a scanned barcode → add it and move on.
            if (results.length === 1 && results[0].sku === term) {
                addComponent(results[0]);
                comboSearch.value = '';
                picked = null;
            }
        }, 250);
    });

    comboAdd.addEventListener('click', function () {
        if (! picked) return;

        addComponent(picked);
        comboSearch.value = '';
        picked = null;
        comboSearch.focus();
    });

    comboSearch.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            comboAdd.click();
        }
    });

    comboBody.addEventListener('click', function (event) {
        if (event.target.closest('[data-remove-combo]')) {
            event.target.closest('[data-combo-row]').remove();
            refresh();
        }
    });

    refresh();
})();
</script>
@endpush
