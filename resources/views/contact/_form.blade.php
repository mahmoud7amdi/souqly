{{-- Contact form, shared by create and edit. --}}
@php
    $contact = $contact ?? null;
    $isEdit = ! is_null($contact);
@endphp

<div class="grid gap-6 lg:grid-cols-3">

    <x-panel :title="__('lang_v1.contact_details')" icon="user" class="lg:col-span-2">
        <div class="form-grid">
            <div class="field">
                <label for="type" class="label label-required">{{ __('lang_v1.type') }}</label>
                <select id="type" name="type"
                        @class(['select', 'input-invalid' => $errors->has('type')]) required>
                    @foreach ($types as $value => $name)
                        <option value="{{ $value }}"
                            @selected(old('type', $contact->type ?? ($defaultType ?? 'customer')) === $value)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                @error('type')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="contact_id" class="label">{{ __('lang_v1.contact_id') }}</label>
                <input id="contact_id" name="contact_id"
                       @class(['input force-ltr', 'input-invalid' => $errors->has('contact_id')])
                       value="{{ old('contact_id', $contact->contact_id ?? ($suggestedContactId ?? '')) }}">
                @error('contact_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="name" class="label label-required">{{ __('lang_v1.name') }}</label>
                <input id="name" name="name" @class(['input', 'input-invalid' => $errors->has('name')])
                       value="{{ old('name', $contact->name ?? '') }}" required>
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="supplier_business_name" class="label">{{ __('lang_v1.business_name') }}</label>
                <input id="supplier_business_name" name="supplier_business_name" class="input"
                       value="{{ old('supplier_business_name', $contact->supplier_business_name ?? '') }}">
            </div>

            <div class="field">
                <label for="mobile" class="label">{{ __('lang_v1.mobile') }}</label>
                <input id="mobile" name="mobile" type="tel" class="input force-ltr" inputmode="tel"
                       value="{{ old('mobile', $contact->mobile ?? '') }}">
            </div>

            <div class="field">
                <label for="email" class="label">{{ __('lang_v1.email') }}</label>
                <input id="email" name="email" type="email"
                       @class(['input force-ltr', 'input-invalid' => $errors->has('email')])
                       value="{{ old('email', $contact->email ?? '') }}">
                @error('email')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="landline" class="label">{{ __('lang_v1.landline') }}</label>
                <input id="landline" name="landline" type="tel" class="input force-ltr" inputmode="tel"
                       value="{{ old('landline', $contact->landline ?? '') }}">
            </div>

            <div class="field">
                <label for="tax_number" class="label">{{ __('lang_v1.tax_number') }}</label>
                <input id="tax_number" name="tax_number" class="input force-ltr"
                       value="{{ old('tax_number', $contact->tax_number ?? '') }}">
            </div>
        </div>
    </x-panel>

    <div class="grid gap-6 self-start">

        <x-panel :title="__('lang_v1.payment_terms')" icon="calendar">
            <div class="grid gap-4">
                <div class="field">
                    <label for="customer_group_id" class="label">{{ __('lang_v1.customer_group') }}</label>
                    <select id="customer_group_id" name="customer_group_id" class="select">
                        @foreach ($customerGroups as $id => $name)
                            <option value="{{ $id }}"
                                @selected(old('customer_group_id', $contact->customer_group_id ?? '') == $id)>
                                {{ $name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="credit_limit" class="label">{{ __('lang_v1.credit_limit') }}</label>
                    <input id="credit_limit" name="credit_limit" class="input-numeric" inputmode="decimal"
                           value="{{ old('credit_limit', $contact->credit_limit ?? '') }}">
                    <p class="hint">{{ __('lang_v1.credit_limit_hint') }}</p>
                </div>

                {{-- Number and unit are one answer ("30 days"), so they share a row
                     and the unit carries no second label — the &nbsp; placeholder
                     this replaced read to a screen reader as an empty question. --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="field">
                        <label for="pay_term_number" class="label">{{ __('lang_v1.pay_term') }}</label>
                        <input id="pay_term_number" name="pay_term_number" class="input-numeric"
                               inputmode="numeric"
                               value="{{ old('pay_term_number', $contact->pay_term_number ?? '') }}">
                    </div>
                    <div class="field self-end">
                        <select id="pay_term_type" name="pay_term_type" class="select"
                                aria-label="{{ __('lang_v1.pay_term') }}">
                            <option value="">—</option>
                            <option value="days" @selected(old('pay_term_type', $contact->pay_term_type ?? '') === 'days')>
                                {{ __('lang_v1.days') }}
                            </option>
                            <option value="months" @selected(old('pay_term_type', $contact->pay_term_type ?? '') === 'months')>
                                {{ __('lang_v1.months') }}
                            </option>
                        </select>
                    </div>
                </div>

                {{-- Create only. Afterwards the balance is a ledger entry with its own
                     screen, because changing it silently would rewrite history. --}}
                @unless ($isEdit)
                    <div class="field">
                        <label for="opening_balance" class="label">{{ __('lang_v1.opening_balance') }}</label>
                        <input id="opening_balance" name="opening_balance" class="input-numeric"
                               inputmode="decimal" value="{{ old('opening_balance') }}">
                        <p class="hint">{{ __('lang_v1.opening_balance_hint') }}</p>
                    </div>
                @endunless
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.address')" icon="pin">
            <div class="grid gap-4">
                @foreach ([
                    'landmark' => __('lang_v1.address_line'),
                    'city' => __('lang_v1.city'),
                    'state' => __('lang_v1.state'),
                    'country' => __('lang_v1.country'),
                    'zip_code' => __('lang_v1.zip_code'),
                ] as $field => $labelText)
                    <div class="field">
                        <label for="{{ $field }}" class="label">{{ $labelText }}</label>
                        <input id="{{ $field }}" name="{{ $field }}"
                               @class(['input', 'force-ltr' => $field === 'zip_code'])
                               value="{{ old($field, $contact->{$field} ?? '') }}">
                    </div>
                @endforeach
            </div>
        </x-panel>
    </div>
</div>
