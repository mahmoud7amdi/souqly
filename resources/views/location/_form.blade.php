{{--
    Business-location form, shared by create and edit.

    Expects: $record (null on create) plus the dropdown data from
    BusinessLocationController::formViewData() — $invoiceSchemes, $invoiceLayouts,
    $priceGroups, $printers.

    Grouped into three panels rather than one long column: identity, address, and
    the invoicing/printing wiring are three separate questions, and a location has
    enough fields that a flat list reads as a wall. The invoice scheme and layout
    dropdowns are marked required because their columns are NOT NULL foreign keys —
    the controller validates the same way, this only tells the user first.
--}}
@php
    $record = $record ?? null;

    $val = fn (string $field, $default = '') => old($field, $record->{$field} ?? $default);

    /* On create the row does not exist yet, so "active" is the sensible default
       and the toggle is not offered until there is a record to deactivate. */
    $isActive = old('is_active', $record->is_active ?? true);
    $printerType = old('receipt_printer_type', $record->receipt_printer_type ?? 'browser');
@endphp

<div class="grid gap-6">
    <x-panel :title="__('lang_v1.location_details')" icon="store">
        <div class="form-grid">
            <div class="field">
                <label for="name" class="label label-required">{{ __('lang_v1.name') }}</label>
                <input id="name" name="name" @class(['input', 'input-invalid' => $errors->has('name')])
                       value="{{ $val('name') }}" required>
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="location_id" class="label">{{ __('lang_v1.location_id') }}</label>
                <input id="location_id" name="location_id"
                       @class(['input force-ltr', 'input-invalid' => $errors->has('location_id')])
                       value="{{ $val('location_id') }}">
                <p class="hint">{{ __('lang_v1.location_id_hint') }}</p>
                @error('location_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="mobile" class="label">{{ __('lang_v1.mobile') }}</label>
                <input id="mobile" name="mobile"
                       @class(['input force-ltr', 'input-invalid' => $errors->has('mobile')])
                       value="{{ $val('mobile') }}">
                @error('mobile')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="alternate_number" class="label">{{ __('lang_v1.alternate_number') }}</label>
                <input id="alternate_number" name="alternate_number" class="input force-ltr"
                       value="{{ $val('alternate_number') }}">
            </div>

            <div class="field">
                <label for="email" class="label">{{ __('lang_v1.email') }}</label>
                <input type="email" id="email" name="email"
                       @class(['input force-ltr', 'input-invalid' => $errors->has('email')])
                       value="{{ $val('email') }}">
                @error('email')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="website" class="label">{{ __('lang_v1.website') }}</label>
                <input id="website" name="website" class="input force-ltr" value="{{ $val('website') }}">
            </div>
        </div>
    </x-panel>

    <x-panel :title="__('lang_v1.address')" icon="pin">
        <div class="form-grid">
            <div class="field sm:col-span-2">
                <label for="landmark" class="label">{{ __('lang_v1.landmark') }}</label>
                <input id="landmark" name="landmark" class="input" value="{{ $val('landmark') }}">
            </div>

            <div class="field">
                <label for="city" class="label">{{ __('lang_v1.city') }}</label>
                <input id="city" name="city" class="input" value="{{ $val('city') }}">
            </div>

            <div class="field">
                <label for="state" class="label">{{ __('lang_v1.state') }}</label>
                <input id="state" name="state" class="input" value="{{ $val('state') }}">
            </div>

            <div class="field">
                <label for="country" class="label">{{ __('lang_v1.country') }}</label>
                <input id="country" name="country" class="input" value="{{ $val('country') }}">
            </div>

            <div class="field">
                <label for="zip_code" class="label">{{ __('lang_v1.zip_code') }}</label>
                <input id="zip_code" name="zip_code" class="input force-ltr" value="{{ $val('zip_code') }}">
            </div>
        </div>
    </x-panel>

    <x-panel :title="__('lang_v1.invoicing')" icon="document">
        <div class="form-grid">
            <div class="field">
                <label for="invoice_scheme_id" class="label label-required">{{ __('lang_v1.invoice_scheme') }}</label>
                <select id="invoice_scheme_id" name="invoice_scheme_id"
                        @class(['select', 'input-invalid' => $errors->has('invoice_scheme_id')]) required>
                    @foreach ($invoiceSchemes as $id => $name)
                        <option value="{{ $id }}" @selected((string) $val('invoice_scheme_id') === (string) $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                @error('invoice_scheme_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="invoice_layout_id" class="label label-required">{{ __('lang_v1.invoice_layout') }}</label>
                <select id="invoice_layout_id" name="invoice_layout_id"
                        @class(['select', 'input-invalid' => $errors->has('invoice_layout_id')]) required>
                    @foreach ($invoiceLayouts as $id => $name)
                        <option value="{{ $id }}" @selected((string) $val('invoice_layout_id') === (string) $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                @error('invoice_layout_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="sale_invoice_scheme_id" class="label">{{ __('lang_v1.sale_invoice_scheme') }}</label>
                <select id="sale_invoice_scheme_id" name="sale_invoice_scheme_id" class="select">
                    <option value="">{{ __('lang_v1.same_as_default') }}</option>
                    @foreach ($invoiceSchemes as $id => $name)
                        <option value="{{ $id }}" @selected((string) $val('sale_invoice_scheme_id') === (string) $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                <p class="hint">{{ __('lang_v1.sale_invoice_scheme_hint') }}</p>
            </div>

            <div class="field">
                <label for="sale_invoice_layout_id" class="label">{{ __('lang_v1.sale_invoice_layout') }}</label>
                <select id="sale_invoice_layout_id" name="sale_invoice_layout_id" class="select">
                    <option value="">{{ __('lang_v1.same_as_default') }}</option>
                    @foreach ($invoiceLayouts as $id => $name)
                        <option value="{{ $id }}" @selected((string) $val('sale_invoice_layout_id') === (string) $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="selling_price_group_id" class="label">{{ __('lang_v1.default_price_group') }}</label>
                <select id="selling_price_group_id" name="selling_price_group_id" class="select">
                    @foreach ($priceGroups as $id => $name)
                        <option value="{{ $id }}" @selected((string) $val('selling_price_group_id') === (string) $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                <p class="hint">{{ __('lang_v1.default_price_group_hint') }}</p>
            </div>
        </div>
    </x-panel>

    <x-panel :title="__('lang_v1.receipt_printing')" icon="printer">
        <div class="form-grid">
            <div class="field">
                <label for="receipt_printer_type" class="label label-required">{{ __('lang_v1.receipt_printer_type') }}</label>
                <select id="receipt_printer_type" name="receipt_printer_type" class="select"
                        data-printer-type>
                    <option value="browser" @selected($printerType === 'browser')>{{ __('lang_v1.browser') }}</option>
                    <option value="printer" @selected($printerType === 'printer')>{{ __('lang_v1.configured_printer') }}</option>
                </select>
                <p class="hint">{{ __('lang_v1.receipt_printer_type_hint') }}</p>
            </div>

            <div class="field" data-printer-field @if ($printerType !== 'printer') hidden @endif>
                <label for="printer_id" class="label">{{ __('lang_v1.printer') }}</label>
                <select id="printer_id" name="printer_id"
                        @class(['select', 'input-invalid' => $errors->has('printer_id')])>
                    @foreach ($printers as $id => $name)
                        <option value="{{ $id }}" @selected((string) $val('printer_id') === (string) $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                @error('printer_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field sm:col-span-2">
                <label class="checkbox-row">
                    <input type="checkbox" name="print_receipt_on_invoice" value="1" class="checkbox"
                           @checked(old('print_receipt_on_invoice', $record->print_receipt_on_invoice ?? true))>
                    <span class="checkbox-label">{{ __('lang_v1.print_receipt_on_invoice') }}</span>
                </label>
            </div>

            @if ($record)
                <div class="field sm:col-span-2">
                    <label class="checkbox-row">
                        <input type="checkbox" name="is_active" value="1" class="checkbox" @checked($isActive)>
                        <span class="checkbox-label">
                            {{ __('lang_v1.active') }}
                            <span class="checkbox-hint">{{ __('lang_v1.location_active_hint') }}</span>
                        </span>
                    </label>
                </div>
            @endif
        </div>
    </x-panel>
</div>

{{-- The printer dropdown only makes sense for the "configured printer" mode; a
     tiny inline script shows it on demand rather than pulling in a component. --}}
@push('scripts')
<script>
    (function () {
        const select = document.querySelector('[data-printer-type]');
        const field = document.querySelector('[data-printer-field]');
        if (!select || !field) return;
        select.addEventListener('change', function () {
            field.hidden = this.value !== 'printer';
        });
    })();
</script>
@endpush
