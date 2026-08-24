@extends('layouts.app')
@section('title', __('lang_v1.business_settings'))
@section('page_title', __('lang_v1.business_settings'))

@section('content')

@php
    $dateFormats = \App\Http\Controllers\BusinessController::dateFormats();
    $enabled = (array) old('enabled_modules', $business->enabled_modules ?? []);

    /* Month names come from the formatter rather than a hardcoded list so the
       financial-year select reads in Arabic on an Arabic screen. */
    $months = collect(range(1, 12))->mapWithKeys(fn (int $m) => [
        $m => \Carbon\Carbon::create(null, $m, 1)->translatedFormat('F'),
    ]);
@endphp

<x-page-head :subtitle="$business->name"/>

{{-- `enctype`: the logo is a file, and a urlencoded form posts a file input as
     its filename and nothing else. --}}
<form method="POST" action="{{ route('business.settings.update') }}"
      enctype="multipart/form-data" class="max-w-4xl">
    @csrf
    @method('PUT')

    <div class="grid gap-6">
        <x-panel :title="__('lang_v1.business_details')" icon="store">
            <div class="form-grid">
                <div class="field sm:col-span-2">
                    <label for="name" class="label label-required">{{ __('lang_v1.business_name') }}</label>
                    <input id="name" name="name" required
                           @class(['input', 'input-invalid' => $errors->has('name')])
                           value="{{ old('name', $business->name) }}">
                    @error('name')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                {{-- The logo, above the picker when there is one. Handled the
                     same way as the invoice-layout uploads: a separate "remove"
                     checkbox, because an empty file input means "I did not choose
                     a file", which is the normal case on every other save. --}}
                <div class="field sm:col-span-2">
                    <label for="logo" class="label">{{ __('lang_v1.logo') }}</label>

                    @if ($logoUrl)
                        <div class="file-current">
                            <span class="thumb-md">
                                <img src="{{ $logoUrl }}" alt="{{ __('lang_v1.logo') }}">
                            </span>
                            <label class="checkbox-row">
                                <input type="checkbox" name="remove_logo" value="1" class="checkbox">
                                <span class="checkbox-label">{{ __('lang_v1.remove') }}</span>
                            </label>
                        </div>
                    @endif

                    <input type="file" id="logo" name="logo" accept="image/*"
                           @class(['input-file', 'input-invalid' => $errors->has('logo')])
                           aria-describedby="logo-hint">
                    <p id="logo-hint" class="hint">{{ __('lang_v1.logo_hint') }}</p>
                    @error('logo')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="start_date" class="label">{{ __('lang_v1.start_date') }}</label>
                    <input type="date" id="start_date" name="start_date" class="input force-ltr"
                           value="{{ old('start_date', $business->start_date?->format('Y-m-d')) }}">
                    @error('start_date')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="time_zone" class="label label-required">{{ __('lang_v1.time_zone') }}</label>
                    <select id="time_zone" name="time_zone" class="select force-ltr" required>
                        @foreach ($timezones as $zone)
                            <option value="{{ $zone }}" @selected(old('time_zone', $business->time_zone) === $zone)>
                                {{ $zone }}
                            </option>
                        @endforeach
                    </select>
                    @error('time_zone')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="fy_start_month" class="label label-required">
                        {{ __('lang_v1.financial_year_start_month') }}
                    </label>
                    <select id="fy_start_month" name="fy_start_month" class="select" required>
                        @foreach ($months as $number => $label)
                            <option value="{{ $number }}"
                                @selected((int) old('fy_start_month', $business->fy_start_month) === $number)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('fy_start_month')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="accounting_method" class="label label-required">
                        {{ __('lang_v1.stock_accounting_method') }}
                    </label>
                    <select id="accounting_method" name="accounting_method" class="select" required>
                        @foreach (['fifo', 'lifo', 'avco'] as $method)
                            <option value="{{ $method }}"
                                @selected(old('accounting_method', $business->accounting_method) === $method)>
                                {{ __('lang_v1.'.$method) }}
                            </option>
                        @endforeach
                    </select>
                    <p class="hint">{{ __('lang_v1.stock_accounting_method_hint') }}</p>
                    @error('accounting_method')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.tax_settings')" icon="percent">
            <div class="form-grid">
                <div class="field">
                    <label for="tax_label_1" class="label">{{ __('lang_v1.tax_1_name') }}</label>
                    <input id="tax_label_1" name="tax_label_1" class="input"
                           value="{{ old('tax_label_1', $business->tax_label_1) }}"
                           placeholder="{{ __('lang_v1.tax_1_name_placeholder') }}">
                    @error('tax_label_1')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="tax_number_1" class="label">{{ __('lang_v1.tax_1_number') }}</label>
                    <input id="tax_number_1" name="tax_number_1" class="input force-ltr"
                           value="{{ old('tax_number_1', $business->tax_number_1) }}">
                    @error('tax_number_1')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="tax_label_2" class="label">{{ __('lang_v1.tax_2_name') }}</label>
                    <input id="tax_label_2" name="tax_label_2" class="input"
                           value="{{ old('tax_label_2', $business->tax_label_2) }}">
                    @error('tax_label_2')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="tax_number_2" class="label">{{ __('lang_v1.tax_2_number') }}</label>
                    <input id="tax_number_2" name="tax_number_2" class="input force-ltr"
                           value="{{ old('tax_number_2', $business->tax_number_2) }}">
                    @error('tax_number_2')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="default_sales_tax" class="label">{{ __('lang_v1.default_sales_tax') }}</label>
                    <select id="default_sales_tax" name="default_sales_tax" class="select">
                        @foreach ($taxRates as $id => $name)
                            <option value="{{ $id }}"
                                @selected((string) old('default_sales_tax', $business->default_sales_tax) === (string) $id)>
                                {{ $name }}
                            </option>
                        @endforeach
                    </select>
                    @error('default_sales_tax')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="sell_price_tax" class="label label-required">
                        {{ __('lang_v1.selling_price_tax') }}
                    </label>
                    <select id="sell_price_tax" name="sell_price_tax" class="select" required>
                        @foreach (['includes', 'excludes'] as $option)
                            <option value="{{ $option }}"
                                @selected(old('sell_price_tax', $business->sell_price_tax) === $option)>
                                {{ __('lang_v1.price_tax_'.$option) }}
                            </option>
                        @endforeach
                    </select>
                    @error('sell_price_tax')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.currency_and_formats')" icon="cash">
            {{-- These change how every figure in the app is *printed*, not what it
                 means. Worth saying out loud: a precision change makes historical
                 totals look different without any of them having moved. --}}
            <div class="alert-info">
                <x-nav-icon name="info" :size="5"/>
                <div>
                    <p class="text-sm">{{ __('lang_v1.formatting_only_hint') }}</p>
                </div>
            </div>

            <div class="form-grid mt-4">
                <div class="field sm:col-span-2">
                    <label for="currency_id" class="label label-required">{{ __('lang_v1.currency') }}</label>
                    <select id="currency_id" name="currency_id" class="select" required>
                        @foreach ($currencies as $id => $name)
                            <option value="{{ $id }}"
                                @selected((int) old('currency_id', $business->currency_id) === (int) $id)>
                                {{ $name }}
                            </option>
                        @endforeach
                    </select>
                    @error('currency_id')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="currency_symbol_placement" class="label label-required">
                        {{ __('lang_v1.currency_symbol_placement') }}
                    </label>
                    <select id="currency_symbol_placement" name="currency_symbol_placement" class="select" required>
                        @foreach (['before', 'after'] as $placement)
                            <option value="{{ $placement }}"
                                @selected(old('currency_symbol_placement', $business->currency_symbol_placement) === $placement)>
                                {{ __('lang_v1.symbol_'.$placement) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="date_format" class="label label-required">{{ __('lang_v1.date_format') }}</label>
                    <select id="date_format" name="date_format" class="select force-ltr" required>
                        @foreach ($dateFormats as $format => $example)
                            <option value="{{ $format }}"
                                @selected(old('date_format', $business->date_format) === $format)>
                                {{ $example }}
                            </option>
                        @endforeach
                    </select>
                    @error('date_format')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="time_format" class="label label-required">{{ __('lang_v1.time_format') }}</label>
                    <select id="time_format" name="time_format" class="select" required>
                        @foreach (['12' => __('lang_v1.time_format_12'), '24' => __('lang_v1.time_format_24')] as $value => $label)
                            <option value="{{ $value }}"
                                @selected((string) old('time_format', $business->time_format) === (string) $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="currency_precision" class="label label-required">
                        {{ __('lang_v1.currency_precision') }}
                    </label>
                    <select id="currency_precision" name="currency_precision" class="select" required>
                        @foreach (range(0, 4) as $precision)
                            <option value="{{ $precision }}"
                                @selected((int) old('currency_precision', $business->currency_precision) === $precision)>
                                {{ $precision }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="quantity_precision" class="label label-required">
                        {{ __('lang_v1.quantity_precision') }}
                    </label>
                    <select id="quantity_precision" name="quantity_precision" class="select" required>
                        @foreach (range(0, 4) as $precision)
                            <option value="{{ $precision }}"
                                @selected((int) old('quantity_precision', $business->quantity_precision) === $precision)>
                                {{ $precision }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.sale_and_purchase_defaults')" icon="cart">
            <div class="form-grid">
                <div class="field">
                    <label for="default_profit_percent" class="label">
                        {{ __('lang_v1.default_profit_percent') }}
                    </label>
                    <input type="text" inputmode="decimal" id="default_profit_percent"
                           name="default_profit_percent"
                           @class(['input-numeric', 'input-invalid' => $errors->has('default_profit_percent')])
                           value="{{ old('default_profit_percent', $business->default_profit_percent) }}">
                    <p class="hint">{{ __('lang_v1.default_profit_percent_hint') }}</p>
                    @error('default_profit_percent')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="default_sales_discount" class="label">
                        {{ __('lang_v1.default_sales_discount') }}
                    </label>
                    <input type="text" inputmode="decimal" id="default_sales_discount"
                           name="default_sales_discount"
                           @class(['input-numeric', 'input-invalid' => $errors->has('default_sales_discount')])
                           value="{{ old('default_sales_discount', $business->default_sales_discount) }}">
                    @error('default_sales_discount')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="sku_prefix" class="label">{{ __('lang_v1.sku_prefix') }}</label>
                    <input id="sku_prefix" name="sku_prefix" class="input force-ltr"
                           value="{{ old('sku_prefix', $business->sku_prefix) }}">
                    <p class="hint">{{ __('lang_v1.sku_prefix_hint') }}</p>
                    @error('sku_prefix')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="transaction_edit_days" class="label label-required">
                        {{ __('lang_v1.transaction_edit_days') }}
                    </label>
                    <input type="text" inputmode="decimal" id="transaction_edit_days"
                           name="transaction_edit_days" required
                           @class(['input-numeric', 'input-invalid' => $errors->has('transaction_edit_days')])
                           value="{{ old('transaction_edit_days', $business->transaction_edit_days) }}">
                    <p class="hint">{{ __('lang_v1.transaction_edit_days_hint') }}</p>
                    @error('transaction_edit_days')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="stock_expiry_alert_days" class="label label-required">
                        {{ __('lang_v1.stock_expiry_alert_days') }}
                    </label>
                    <input type="text" inputmode="decimal" id="stock_expiry_alert_days"
                           name="stock_expiry_alert_days" required
                           @class(['input-numeric', 'input-invalid' => $errors->has('stock_expiry_alert_days')])
                           value="{{ old('stock_expiry_alert_days', $business->stock_expiry_alert_days) }}">
                    @error('stock_expiry_alert_days')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.product_features')" icon="box">
            <p class="hint">{{ __('lang_v1.product_features_hint') }}</p>

            <div class="mt-3 grid gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($productToggles as $toggle)
                    <label class="checkbox-row">
                        <input type="checkbox" name="{{ $toggle }}" value="1" class="checkbox"
                               @checked(old($toggle, $business->{$toggle} ?? false))>
                        <span class="checkbox-label text-sm">{{ __('lang_v1.'.$toggle) }}</span>
                    </label>
                @endforeach
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.modules')" icon="layers">
            {{-- Switching a module off hides its sidebar entries and drops its
                 permissions from the role editor; it does not delete anything it
                 has already recorded. --}}
            <p class="hint">{{ __('lang_v1.modules_hint') }}</p>

            <div class="mt-3 grid gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($modules as $key => $label)
                    <label class="checkbox-row">
                        <input type="checkbox" name="enabled_modules[]" value="{{ $key }}" class="checkbox"
                               @checked(in_array($key, $enabled, true))>
                        <span class="checkbox-label text-sm">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            @error('enabled_modules')<p class="field-error">{{ $message }}</p>@enderror
        </x-panel>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.update') }}
        </button>
    </div>
</form>
@endsection
