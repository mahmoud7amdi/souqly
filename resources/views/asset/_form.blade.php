{{--
    The asset register's own form, shared by create and edit.

    Grouped by the question each field answers rather than by column order: what is
    it, what did it cost, and can it be handed to somebody. A register is filled in
    once per asset and then read for years, so the fields that make a row findable
    later — the code on the sticker, the serial, the model — sit at the top where
    they cannot be skipped, and the accounting fields sit beside them rather than
    below the fold.

    Two fields become constrained once something is signed out, and the form says so
    up front. `AssetService::update()` refuses both cases, but a person who has typed
    a new quantity into a form and then lost the whole edit to a redirect has learnt
    the rule the expensive way.
--}}
@php
    $record = $record ?? null;
    $allocated = $allocated ?? 0.0;
    $hasOutstanding = $allocated > 0;

    /* Checked by default on a new asset: most things a business bothers to put in a
       register are things it hands to people. Forced on while anything is out. */
    $isAllocatable = (bool) old('is_allocatable', $record->is_allocatable ?? true) || $hasOutstanding;
@endphp

<div class="grid gap-6 lg:grid-cols-3">

    {{-- ============ What it is ============ --}}
    <x-panel :title="__('assetmanagement.asset_details')" icon="box"
             :subtitle="__('assetmanagement.asset_details_hint')" class="lg:col-span-2">
        <div class="form-grid">
            <div class="field sm:col-span-2">
                <label for="name" class="label label-required">{{ __('lang_v1.name') }}</label>
                <input id="name" name="name"
                       @class(['input', 'input-invalid' => $errors->has('name')])
                       value="{{ old('name', $record->name ?? '') }}"
                       placeholder="{{ __('assetmanagement.asset_name_placeholder') }}"
                       maxlength="255" required autofocus>
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="asset_code" class="label">{{ __('assetmanagement.asset_code') }}</label>
                <input id="asset_code" name="asset_code"
                       @class(['input', 'force-ltr', 'input-invalid' => $errors->has('asset_code')])
                       value="{{ old('asset_code', $record->asset_code ?? '') }}"
                       maxlength="255">
                <p class="hint">{{ __('assetmanagement.asset_code_hint') }}</p>
                @error('asset_code')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="location_id" class="label">{{ __('lang_v1.business_location') }}</label>
                <select id="location_id" name="location_id"
                        @class(['select', 'input-invalid' => $errors->has('location_id')])>
                    @foreach ($locations as $id => $name)
                        <option value="{{ $id }}"
                                @selected((string) old('location_id', $record->location_id ?? '') === (string) $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                <p class="hint">{{ __('assetmanagement.asset_location_hint') }}</p>
                @error('location_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="model" class="label">{{ __('assetmanagement.model') }}</label>
                <input id="model" name="model"
                       @class(['input', 'input-invalid' => $errors->has('model')])
                       value="{{ old('model', $record->model ?? '') }}" maxlength="255">
                @error('model')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="serial_no" class="label">{{ __('assetmanagement.serial_no') }}</label>
                <input id="serial_no" name="serial_no"
                       @class(['input', 'force-ltr', 'input-invalid' => $errors->has('serial_no')])
                       value="{{ old('serial_no', $record->serial_no ?? '') }}" maxlength="255">
                @error('serial_no')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="quantity" class="label label-required">{{ __('lang_v1.quantity') }}</label>
                <input type="number" step="0.0001" min="{{ $hasOutstanding ? $allocated : 0 }}"
                       id="quantity" name="quantity"
                       @class(['input', 'input-numeric', 'input-invalid' => $errors->has('quantity')])
                       value="{{ old('quantity', $record->quantity ?? 1) }}" required>
                @if ($hasOutstanding)
                    <p class="hint">{{ __('assetmanagement.quantity_floor_hint', ['allocated' => format_quantity($allocated)]) }}</p>
                @else
                    <p class="hint">{{ __('assetmanagement.quantity_hint') }}</p>
                @endif
                @error('quantity')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="unit_price" class="label">{{ __('assetmanagement.unit_price') }}</label>
                <input type="number" step="0.0001" min="0" id="unit_price" name="unit_price"
                       @class(['input', 'input-amount', 'input-invalid' => $errors->has('unit_price')])
                       value="{{ old('unit_price', $record->unit_price ?? 0) }}">
                <p class="hint">{{ __('assetmanagement.unit_price_hint') }}</p>
                @error('unit_price')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field sm:col-span-2">
                <label for="description" class="label">{{ __('lang_v1.description') }}</label>
                <textarea id="description" name="description" rows="3"
                          @class(['textarea', 'input-invalid' => $errors->has('description')])
                          maxlength="2000">{{ old('description', $record->description ?? '') }}</textarea>
                @error('description')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- Set apart from the grid because it is not a property of the object, it
             is a decision about how the object will be used — and it is the field
             that decides whether the allocation panel appears at all. --}}
        <div class="surface-quiet mt-6">
            <div class="checkbox-row">
                <input type="hidden" name="is_allocatable" value="{{ $hasOutstanding ? 1 : 0 }}">
                <input type="checkbox" id="is_allocatable" name="is_allocatable" value="1"
                       class="checkbox" @checked($isAllocatable) @disabled($hasOutstanding)>
                <div>
                    <label for="is_allocatable" class="checkbox-label">
                        {{ __('assetmanagement.is_allocatable') }}
                    </label>
                    {{-- A disabled checkbox submits nothing, so the hidden field
                         above carries the true value in that case rather than the
                         `0` that would read as "switch this off" — which is exactly
                         the edit AssetService::update() refuses. --}}
                    <p class="checkbox-hint">
                        {{ $hasOutstanding
                            ? __('assetmanagement.is_allocatable_locked')
                            : __('assetmanagement.is_allocatable_hint') }}
                    </p>
                </div>
            </div>
            @error('is_allocatable')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </x-panel>

    {{-- ============ What it cost ============ --}}
    <x-panel :title="__('assetmanagement.acquisition')" icon="receipt"
             :subtitle="__('assetmanagement.acquisition_hint')" class="self-start">
        <div class="grid gap-5">
            <div class="field">
                <label for="purchase_date" class="label">{{ __('assetmanagement.purchase_date') }}</label>
                <input type="date" id="purchase_date" name="purchase_date"
                       @class(['input', 'input-invalid' => $errors->has('purchase_date')])
                       value="{{ old('purchase_date', $record?->purchase_date ? \Illuminate\Support\Carbon::parse($record->purchase_date)->format('Y-m-d') : '') }}">
                <p class="hint">{{ __('assetmanagement.purchase_date_hint') }}</p>
                @error('purchase_date')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="purchase_type" class="label">{{ __('assetmanagement.purchase_type') }}</label>
                <select id="purchase_type" name="purchase_type"
                        @class(['select', 'input-invalid' => $errors->has('purchase_type')])>
                    @foreach ($purchaseTypes as $key => $label)
                        <option value="{{ $key }}"
                                @selected((string) old('purchase_type', $record->purchase_type ?? '') === (string) $key)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('purchase_type')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="depreciation" class="label">{{ __('assetmanagement.depreciation_rate') }}</label>
                <input type="number" step="0.01" min="0" max="100" id="depreciation" name="depreciation"
                       @class(['input', 'input-numeric', 'input-invalid' => $errors->has('depreciation')])
                       value="{{ old('depreciation', $record->depreciation ?? '') }}">
                <p class="hint">{{ __('assetmanagement.depreciation_rate_hint') }}</p>
                @error('depreciation')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>

        <x-slot:footer>
            <p class="text-sm text-slate-600">{{ __('assetmanagement.depreciation_note') }}</p>
        </x-slot:footer>
    </x-panel>
</div>
