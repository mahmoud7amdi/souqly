@extends('layouts.app')
@section('title', $account->name)
@section('page_title', __('lang_v1.payment_accounts'))

@section('content')

@php
    $isClosed = (bool) $account->is_closed;
    $isFiltered = request()->filled('sub_type') || request()->filled('type')
        || request()->filled('start_date') || request()->filled('end_date');

    /* One row may be opened for correction at a time, named in the query string.
       That is the whole mechanism: no modal, no second route, no JavaScript — and
       the URL of a half-finished correction is shareable and survives a reload.
       `entriesQuery()` reads only sub_type/type/dates, so `edit` passes through
       the filters untouched. */
    $editingId = (int) request('edit');

    $canCorrect = $canEditEntry || $canDeleteEntry;
    $columnCount = 6 + (int) $canCorrect;
@endphp

<x-page-head :title="$account->name" :back="route('accounts.index')"
             :backLabel="__('lang_v1.payment_accounts')">
    <x-slot:subtitle>
        @if ($account->account_number)
            <span class="force-ltr">{{ $account->account_number }}</span>
            <span class="text-slate-300">·</span>
        @endif
        {{ $account->account_type_name ?? __('lang_v1.'.($account->account_type ?: 'saving_current')) }}
        @if ($isClosed)
            <span class="badge-muted ms-1">{{ __('lang_v1.closed') }}</span>
        @endif
    </x-slot:subtitle>

    <a href="{{ route('accounts.edit', $account->id) }}" class="btn-secondary">
        <x-nav-icon name="edit" :size="4"/>
        {{ __('lang_v1.edit') }}
    </a>

    {{-- Close and reopen are the same action in two directions, so they are one
         form with the direction posted rather than two routes. There is no delete
         here at all: the controller does not offer one, on purpose. --}}
    <form method="POST" action="{{ route('accounts.setClosed', $account->id) }}"
          @unless ($isClosed) data-confirm="{{ __('lang_v1.confirm_close_account') }}" @endunless>
        @csrf
        <input type="hidden" name="closed" value="{{ $isClosed ? 0 : 1 }}">
        <button type="submit" class="btn-secondary">
            <x-nav-icon :name="$isClosed ? 'refresh' : 'lock'" :size="4"/>
            {{ $isClosed ? __('lang_v1.reopen') : __('lang_v1.close') }}
        </button>
    </form>
</x-page-head>

@if ($canSeeBalance)
    <div class="section">
        <div class="grid gap-4 sm:grid-cols-3">
            <x-stat :label="__('lang_v1.money_in')" :value="format_currency($totals['in'])" icon="download"/>
            <x-stat :label="__('lang_v1.money_out')" :value="format_currency($totals['out'])" icon="upload"/>
            {{-- Toned only when it is negative, and then because an overdraft is
                 worth noticing — not because it is invalid. --}}
            <x-stat :label="__('lang_v1.balance')"
                    :value="format_currency($totals['balance'])"
                    icon="scale"
                    :tone="$totals['balance'] < 0 ? 'danger' : null"
                    :hint="$totals['balance'] < 0 ? __('lang_v1.overdrawn') : null"/>
        </div>
    </div>
@endif

<div class="section grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
        @if ($isClosed)
            <div class="alert-warning">
                <x-nav-icon name="lock" :size="5"/>
                <div>
                    <p class="font-semibold">{{ __('lang_v1.account_is_closed') }}</p>
                    <p class="mt-0.5 text-sm">{{ __('lang_v1.reopen_to_move_money_again') }}</p>
                </div>
            </div>
        @else
            {{--
                One set of fields, three destinations. Deposit is the form's own
                action; withdraw and transfer carry `formaction` on their buttons.
                Each route validates only what it needs, so the extra posted fields
                are ignored rather than rejected — which means all of this works
                with JavaScript switched off, and no field has to be revealed or
                hidden to make the shape of the form match the button pressed.

                Deposit is deliberately the first submit in document order: pressing
                Enter in the amount field triggers the first one, and money arriving
                is both the commoner event and the gentler mistake. The visual order
                puts it last, where every commit button on every screen sits.
            --}}
            <form method="POST" action="{{ route('accounts.deposit', $account->id) }}">
                @csrf

                <x-panel :title="__('lang_v1.record_a_movement')" icon="transfer"
                         :subtitle="__('lang_v1.money_in_out_or_across')">
                    <div class="form-grid-3">
                        <div class="field">
                            <label for="amount" class="label label-required">{{ __('lang_v1.amount') }}</label>
                            <input id="amount" name="amount" type="text" inputmode="decimal"
                                   value="{{ old('amount') }}"
                                   class="input input-numeric input-lg @error('amount') input-invalid @enderror"
                                   placeholder="0.00" required>
                            @error('amount')<p class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field">
                            <label for="operation_date" class="label label-required">{{ __('lang_v1.date') }}</label>
                            <input id="operation_date" name="operation_date" type="date"
                                   value="{{ old('operation_date', now()->format('Y-m-d')) }}"
                                   class="input @error('operation_date') input-invalid @enderror" required>
                            @error('operation_date')<p class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field">
                            <label for="reff_no" class="label">{{ __('lang_v1.reference_no') }}</label>
                            <input id="reff_no" name="reff_no" dir="ltr" value="{{ old('reff_no') }}"
                                   class="input @error('reff_no') input-invalid @enderror">
                            @error('reff_no')
                                <p class="field-error">{{ $message }}</p>
                            @else
                                <p class="hint">{{ __('lang_v1.leave_blank_to_generate') }}</p>
                            @enderror
                        </div>

                        <div class="field sm:col-span-2 lg:col-span-3">
                            <label for="note" class="label">{{ __('lang_v1.note') }}</label>
                            <input id="note" name="note" value="{{ old('note') }}"
                                   class="input @error('note') input-invalid @enderror"
                                   placeholder="{{ __('lang_v1.what_this_movement_was_for') }}">
                            @error('note')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="mt-6 flex flex-wrap items-center justify-end gap-2">
                        <button type="submit" class="btn-accent order-2">
                            <x-nav-icon name="download" :size="4"/>
                            {{ __('lang_v1.deposit') }}
                        </button>

                        <button type="submit" class="btn-secondary order-1"
                                formaction="{{ route('accounts.withdraw', $account->id) }}">
                            <x-nav-icon name="upload" :size="4"/>
                            {{ __('lang_v1.withdraw') }}
                        </button>
                    </div>

                    {{-- The transfer keeps its own tinted block because it needs one
                         field the other two do not, and a button next to the field
                         it depends on cannot be pressed by accident with the field
                         empty and no explanation. --}}
                    <div class="surface-quiet mt-6">
                        <p class="section-label">{{ __('lang_v1.or_move_it_to_another_account') }}</p>

                        @if (empty($transferTargets))
                            <p class="hint">{{ __('lang_v1.transfer_needs_a_second_open_account') }}</p>
                        @else
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="field min-w-56 flex-1">
                                    <label for="to_account_id" class="label">
                                        {{ __('lang_v1.transfer_to') }}
                                    </label>
                                    <select id="to_account_id" name="to_account_id" class="select">
                                        <option value="">{{ __('lang_v1.select_account') }}</option>
                                        @foreach ($transferTargets as $id => $name)
                                            <option value="{{ $id }}"
                                                @selected(old('to_account_id') == $id)>{{ $name }}</option>
                                        @endforeach
                                    </select>
                                    @error('to_account_id')<p class="field-error">{{ $message }}</p>@enderror
                                </div>

                                <button type="submit" class="btn-secondary"
                                        formaction="{{ route('accounts.transfer', $account->id) }}">
                                    <x-nav-icon name="transfer" :size="4"/>
                                    {{ __('lang_v1.transfer') }}
                                </button>
                            </div>

                            <p class="hint mt-3">{{ __('lang_v1.transfer_writes_a_row_on_both') }}</p>
                        @endif
                    </div>
                </x-panel>
            </form>
        @endif
    </div>

    <x-panel :title="__('lang_v1.account_details')" icon="bank" class="self-start">
        <x-attr-list :columns="1" :items="[
            'lang_v1.account_type' => $account->account_type_name,
            'lang_v1.account_kind' => __('lang_v1.'.($account->account_type ?: 'saving_current')),
            'lang_v1.account_number' => $account->account_number,
            'lang_v1.note' => $account->note,
            'lang_v1.created_on' => format_date($account->created_at),
        ]"/>
    </x-panel>
</div>

<div class="section-head">
    <div class="section-head-text">
        <p class="section-eyebrow">{{ __('lang_v1.ledger') }}</p>
        <h2 class="section-title">{{ __('lang_v1.account_transactions') }}</h2>
        <p class="section-desc">{{ __('lang_v1.newest_first_credits_and_debits') }}</p>
    </div>

    <div class="section-actions">
        <span class="text-sm text-slate-500">
            {{ trans_choice('lang_v1.record_count', $entries->total(), ['count' => $entries->total()]) }}
        </span>
    </div>
</div>

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field">
            <label for="sub_type" class="label">{{ __('lang_v1.kind') }}</label>
            <select id="sub_type" name="sub_type" class="select">
                @foreach ($subTypes as $value => $label)
                    <option value="{{ $value }}" @selected(request('sub_type') === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="type" class="label">{{ __('lang_v1.direction') }}</label>
            <select id="type" name="type" class="select">
                <option value="">{{ __('lang_v1.all') }}</option>
                <option value="credit" @selected(request('type') === 'credit')>{{ __('lang_v1.money_in') }}</option>
                <option value="debit" @selected(request('type') === 'debit')>{{ __('lang_v1.money_out') }}</option>
            </select>
        </div>

        <div class="field">
            <label for="start_date" class="label">{{ __('lang_v1.from') }}</label>
            <input type="date" id="start_date" name="start_date" value="{{ request('start_date') }}" class="input">
        </div>

        <div class="field">
            <label for="end_date" class="label">{{ __('lang_v1.to') }}</label>
            <input type="date" id="end_date" name="end_date" value="{{ request('end_date') }}" class="input">
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('accounts.show', $account->id) }}" class="btn-secondary">
                    <x-nav-icon name="x" :size="4"/>
                    {{ __('lang_v1.reset') }}
                </a>
            @endif
        </div>
    </div>
</form>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.date') }}</th>
                <th>{{ __('lang_v1.reference_no') }}</th>
                <th>{{ __('lang_v1.kind') }}</th>
                <th class="th-numeric">{{ __('lang_v1.money_in') }}</th>
                <th class="th-numeric">{{ __('lang_v1.money_out') }}</th>
                <th>{{ __('lang_v1.added_by') }}</th>
                @if ($canCorrect)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                @php
                    /* A row mirrored from a transaction payment is read-only: the
                       payment is the fact and this is its shadow. The service
                       refuses to touch one; the buttons are hidden for the same
                       reason, and both are needed because a hidden button is not
                       a rule (§8). */
                    $isMirrored = ! empty($entry->transaction_payment_id);
                    $isIn = $entry->type === 'credit';

                    /* Withdrawals are stored as `sub_type = deposit` with a debit
                       type — the direction carries the meaning, not the sub_type.
                       So the label is derived from both, and a mirrored row (which
                       has no sub_type at all) reads as what it actually is. */
                    $kind = match (true) {
                        $entry->sub_type === 'opening_balance' => __('lang_v1.opening_balance'),
                        $entry->sub_type === 'fund_transfer' => __('lang_v1.fund_transfer'),
                        $entry->sub_type === 'deposit' => $isIn ? __('lang_v1.deposit') : __('lang_v1.withdraw'),
                        default => __('lang_v1.payment'),
                    };

                    $otherAccount = $entry->transfer_transaction?->account?->name;
                    $isEditing = $editingId === $entry->id && $canEditEntry && ! $isMirrored;
                @endphp

                <tr @class(['bg-brand-50/40' => $isEditing])>
                    <td class="whitespace-nowrap">@format_date($entry->operation_date)</td>

                    <td>
                        <span class="force-ltr">{{ or_dash($entry->reff_no) }}</span>
                        @if ($entry->transaction)
                            <span class="cell-meta">
                                <x-document-link :transaction="$entry->transaction" muted/>
                            </span>
                        @endif
                    </td>

                    <td>
                        <span class="cell-primary">{{ $kind }}</span>
                        @if ($otherAccount)
                            <span class="cell-meta">
                                {{ $isIn
                                    ? __('lang_v1.from_x', ['name' => $otherAccount])
                                    : __('lang_v1.to_x', ['name' => $otherAccount]) }}
                            </span>
                        @elseif ($isMirrored && $entry->transaction?->contact)
                            <span class="cell-meta">{{ $entry->transaction->contact->name }}</span>
                        @elseif ($entry->note)
                            <span class="cell-meta">{{ $entry->note }}</span>
                        @endif
                    </td>

                    <td class="cell-numeric text-emerald-700">
                        {{ $isIn ? format_currency($entry->amount) : '' }}
                    </td>

                    <td class="cell-numeric text-amber-700">
                        {{ $isIn ? '' : format_currency($entry->amount) }}
                    </td>

                    <td class="text-sm text-slate-500">
                        {{ or_dash($entry->created_user->user_full_name ?? null) }}
                    </td>

                    @if ($canCorrect)
                        <td>
                            <div class="cell-actions">
                                @if ($isMirrored)
                                    {{-- A native `title`, not @show_tooltip: the
                                         tooltip directive renders nothing when the
                                         tenant turns tooltips off, and this is the
                                         only explanation for two missing buttons. --}}
                                    <span class="text-slate-300"
                                          title="{{ __('lang_v1.mirrored_from_a_payment') }}"
                                          aria-label="{{ __('lang_v1.mirrored_from_a_payment') }}">
                                        <x-nav-icon name="lock" :size="4"/>
                                    </span>
                                @else
                                    @if ($canEditEntry)
                                        <a href="{{ $isEditing
                                                ? request()->fullUrlWithoutQuery(['edit'])
                                                : request()->fullUrlWithQuery(['edit' => $entry->id]) }}"
                                           class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                           aria-label="{{ __('lang_v1.edit') }}">
                                            <x-nav-icon :name="$isEditing ? 'x' : 'edit'" :size="4"/>
                                        </a>
                                    @endif

                                    @if ($canDeleteEntry)
                                        <form method="POST"
                                              action="{{ route('accounts.transactions.destroy', [$account->id, $entry->id]) }}"
                                              data-confirm="{{ $entry->transfer_transaction_id
                                                    ? __('lang_v1.confirm_delete_both_transfer_legs')
                                                    : __('lang_v1.confirm_delete') }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-icon-danger"
                                                    title="{{ __('lang_v1.delete') }}"
                                                    aria-label="{{ __('lang_v1.delete') }}">
                                                <x-nav-icon name="trash" :size="4"/>
                                            </button>
                                        </form>
                                    @endif
                                @endif
                            </div>
                        </td>
                    @endif
                </tr>

                @if ($isEditing)
                    {{-- The correction form lives in its own row spanning the table
                         rather than inside the cells above: a <form> cannot legally
                         wrap a <tr>, and putting one in each cell would post four
                         separate forms. --}}
                    <tr class="bg-brand-50/40">
                        <td colspan="{{ $columnCount }}" class="pt-0">
                            <form method="POST"
                                  action="{{ route('accounts.transactions.update', [$account->id, $entry->id]) }}"
                                  class="surface-quiet">
                                @csrf
                                @method('PUT')

                                <p class="section-label">{{ __('lang_v1.correct_this_entry') }}</p>

                                <div class="form-grid-3">
                                    <div class="field">
                                        <label for="edit_amount" class="label label-required">
                                            {{ __('lang_v1.amount') }}
                                        </label>
                                        <input id="edit_amount" name="amount" type="text" inputmode="decimal"
                                               value="{{ old('amount', number_format((float) $entry->amount, 2, '.', '')) }}"
                                               class="input input-numeric" required>
                                    </div>

                                    <div class="field">
                                        <label for="edit_operation_date" class="label label-required">
                                            {{ __('lang_v1.date') }}
                                        </label>
                                        <input id="edit_operation_date" name="operation_date" type="date"
                                               value="{{ old('operation_date', $entry->operation_date?->format('Y-m-d')) }}"
                                               class="input" required>
                                    </div>

                                    <div class="field">
                                        <label for="edit_reff_no" class="label">{{ __('lang_v1.reference_no') }}</label>
                                        <input id="edit_reff_no" name="reff_no" dir="ltr"
                                               value="{{ old('reff_no', $entry->reff_no) }}" class="input">
                                    </div>

                                    <div class="field sm:col-span-2 lg:col-span-3">
                                        <label for="edit_note" class="label">{{ __('lang_v1.note') }}</label>
                                        <input id="edit_note" name="note" value="{{ old('note', $entry->note) }}"
                                               class="input">
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
                                    @if ($entry->transfer_transaction_id)
                                        <span class="form-actions-spacer">
                                            {{ __('lang_v1.both_legs_change_together') }}
                                        </span>
                                    @endif

                                    <a href="{{ request()->fullUrlWithoutQuery(['edit']) }}" class="btn-secondary">
                                        {{ __('lang_v1.cancel') }}
                                    </a>

                                    <button type="submit" class="btn-primary">
                                        <x-nav-icon name="save" :size="4"/>
                                        {{ __('lang_v1.update') }}
                                    </button>
                                </div>
                            </form>
                        </td>
                    </tr>
                @endif
            @empty
                <x-table-empty :columns="$columnCount"
                               :icon="$isFiltered ? 'search' : 'receipt'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : __('lang_v1.movements_appear_when_money_moves')">
                    @if ($isFiltered)
                        <a href="{{ route('accounts.show', $account->id) }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $entries->links() }}
</div>
@endsection
