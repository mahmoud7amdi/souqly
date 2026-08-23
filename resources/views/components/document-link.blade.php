{{--
    A transaction rendered as a link to wherever that kind of transaction lives.

    Payments, account entries and register sessions all list rows that point back
    at a document, and without this each would carry its own match() over
    transaction types — four copies of one mapping, drifting apart as screens are
    added.

    `Route::has()` guards the lookup because the modules land in stages: an
    account entry mirroring a stock adjustment has to render before the stock
    screens exist, and it has to render as plain text rather than 500. Same
    reason the sidebar guards its entries (§8).

    A type with no screen of its own — an opening balance, a payroll row — is
    shown as its reference and nothing more. It is a real document; it just is
    not one you can open.
--}}
@props([
    'transaction' => null,
    'muted' => false,
])

@php
    /* Sells carry invoice_no, everything else carries ref_no, and a row that
       somehow has neither still needs to be identifiable — so the id, prefixed,
       rather than an empty cell that reads as a rendering fault. */
    $label = $transaction
        ? ($transaction->invoice_no ?: $transaction->ref_no ?: '#'.$transaction->id)
        : null;

    $routeName = match ($transaction->type ?? null) {
        \App\Support\TransactionTypes::PURCHASE => 'purchases.show',
        \App\Support\TransactionTypes::PURCHASE_ORDER => 'purchase-order.show',
        \App\Support\TransactionTypes::PURCHASE_REQUISITION => 'purchase-requisition.show',
        \App\Support\TransactionTypes::PURCHASE_RETURN => 'purchase-return.show',
        \App\Support\TransactionTypes::SELL => 'sells.show',
        \App\Support\TransactionTypes::SALES_ORDER => 'sales-order.show',
        \App\Support\TransactionTypes::SELL_RETURN => 'sell-return.show',
        \App\Support\TransactionTypes::EXPENSE,
        \App\Support\TransactionTypes::EXPENSE_REFUND => 'expenses.show',
        \App\Support\TransactionTypes::STOCK_ADJUSTMENT => 'stock-adjustments.show',
        \App\Support\TransactionTypes::SELL_TRANSFER,
        \App\Support\TransactionTypes::PURCHASE_TRANSFER => 'stock-transfers.show',
        default => null,
    };

    $href = $routeName && Route::has($routeName)
        ? route($routeName, $transaction->id)
        : null;
@endphp

@if (empty($transaction))
    {{ or_dash(null) }}
@elseif ($href)
    <a href="{{ $href }}" class="{{ $muted ? 'link-muted' : 'cell-link' }} force-ltr">{{ $label }}</a>
@else
    <span class="force-ltr {{ $muted ? 'text-slate-500' : 'font-medium text-slate-700' }}">{{ $label }}</span>
@endif
