{{--
    Add / edit a payment account.

    Two "types" live on this record and they are not variations of one idea, so the
    form names both plainly rather than merging them into a single picker:

      - `account_type_id` — the business's own catalogue ("Bank", "Till", "Wallet").
        Free, optional, and purely descriptive.
      - `account_type` — the accounting kind, `saving_current` or `capital`, which
        decides whether the account may receive payments at all
        ({@see \App\Models\Account::scopeNotCapital()}).

    The opening balance is create-only, because `validateAccount()` accepts it only
    when there is no account yet: it is written once as a movement, and the honest
    way to change it afterwards is another movement. The form says that on edit
    instead of showing a field that would be ignored.

    Expects: account, accountTypes, kinds.
--}}
@php
    $isEdit = ! empty($account);
    $kind = old('account_type', $account->account_type ?? 'saving_current');
@endphp

<form method="POST"
      action="{{ $isEdit ? route('accounts.update', $account->id) : route('accounts.store') }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="max-w-3xl space-y-6">
        <x-panel :title="__('lang_v1.account_details')" icon="bank">
            <div class="form-grid">
                <div class="field">
                    <label for="name" class="label label-required">
                        {{ __('lang_v1.account_name') }}
                    </label>
                    <input id="name" name="name" value="{{ old('name', $account->name ?? '') }}"
                           class="input @error('name') input-invalid @enderror" required autofocus>
                    @error('name')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="account_number" class="label">{{ __('lang_v1.account_number') }}</label>
                    <input id="account_number" name="account_number" dir="ltr"
                           value="{{ old('account_number', $account->account_number ?? '') }}"
                           class="input @error('account_number') input-invalid @enderror">
                    @error('account_number')
                        <p class="field-error">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('lang_v1.shown_next_to_the_name_in_pickers') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="account_type_id" class="label">{{ __('lang_v1.account_type') }}</label>
                    <select id="account_type_id" name="account_type_id" class="select">
                        @foreach ($accountTypes as $id => $name)
                            <option value="{{ $id }}"
                                @selected(old('account_type_id', $account->account_type_id ?? null) == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('account_type_id')
                        <p class="field-error">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('lang_v1.your_own_catalogue_label') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="account_type" class="label">{{ __('lang_v1.account_kind') }}</label>
                    <select id="account_type" name="account_type" class="select">
                        @foreach ($kinds as $value => $label)
                            <option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('account_type')
                        <p class="field-error">{{ $message }}</p>
                    @else
                        {{-- The consequence, not the definition: a capital account
                             disappears from every payment picker, and finding that
                             out from an empty dropdown later is nobody's idea of a
                             good afternoon. --}}
                        <p class="hint">{{ __('lang_v1.capital_accounts_excluded_from_payments') }}</p>
                    @enderror
                </div>

                <div class="field sm:col-span-2">
                    <label for="note" class="label">{{ __('lang_v1.note') }}</label>
                    <textarea id="note" name="note" rows="2"
                              class="textarea @error('note') input-invalid @enderror">{{ old('note', $account->note ?? '') }}</textarea>
                    @error('note')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </x-panel>

        @if (! $isEdit)
            <x-panel :title="__('lang_v1.opening_balance')" icon="scale"
                     :subtitle="__('lang_v1.what_the_account_held_on_day_one')">
                <div class="form-grid">
                    <div class="field">
                        <label for="opening_balance" class="label">{{ __('lang_v1.amount') }}</label>
                        <input id="opening_balance" name="opening_balance" type="text" inputmode="decimal"
                               value="{{ old('opening_balance') }}"
                               class="input input-numeric @error('opening_balance') input-invalid @enderror"
                               placeholder="0.00">
                        @error('opening_balance')
                            <p class="field-error">{{ $message }}</p>
                        @else
                            {{-- Negative is allowed on purpose: an overdrawn account
                                 is a real state, and refusing to record it would only
                                 mean the figure on screen is wrong. --}}
                            <p class="hint">{{ __('lang_v1.negative_means_overdrawn') }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="opening_balance_date" class="label">{{ __('lang_v1.date') }}</label>
                        <input id="opening_balance_date" name="opening_balance_date" type="date"
                               value="{{ old('opening_balance_date', now()->format('Y-m-d')) }}"
                               class="input @error('opening_balance_date') input-invalid @enderror">
                        @error('opening_balance_date')
                            <p class="field-error">{{ $message }}</p>
                        @else
                            <p class="hint">{{ __('lang_v1.written_once_as_a_movement') }}</p>
                        @enderror
                    </div>
                </div>
            </x-panel>
        @endif
    </div>

    <div class="form-actions">
        @if ($isEdit)
            <span class="form-actions-spacer">
                {{ __('lang_v1.opening_balance_is_history_now') }}
            </span>
        @else
            <span class="form-actions-spacer"></span>
        @endif

        <a href="{{ $isEdit ? route('accounts.show', $account->id) : route('accounts.index') }}"
           class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>

        <button type="submit" class="btn-primary">
            <x-nav-icon name="save" :size="4"/>
            {{ $isEdit ? __('lang_v1.update') : __('lang_v1.save') }}
        </button>
    </div>
</form>
