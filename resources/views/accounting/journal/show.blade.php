@extends('layouts.app')
@section('title', __('accounting.journal').' — '.$number)
@section('page_title', __('accounting.journal').' — '.$number)

@section('content')

{{--
    One journal document, as its lines.

    There is no document header table in this schema, so the document-level fields —
    date, name, reference, note, who posted it — are read from the first line, which
    is the only place they exist. `postJournal()` writes them identically onto every
    row of a document, so any line would do; taking the first is the cheapest way to
    say "the document's".

    No edit and no delete, on purpose. A posted document is corrected by reversing
    it: `reversed_document_note` and `no_edit_note` say so on screen, because a clerk
    who cannot find the edit button will otherwise go looking for the database.
--}}

@php
    $head = $lines->first();

    $balanced = abs($debit - $credit) < 0.0001;

    /* `chart_of_account`, `business_location` and `created_by` carry withDefault(),
       so they are always objects. `cost_center` and `contact` do not.

       The sub-type values are the ones the service actually writes: `transfer()`
       stamps `'transfer'` and `reverse()` stamps `'reversal'`. `'fund_transfer'`
       reads better and matches nothing. */
    $subType = match ($head->transaction_sub_type) {
        'transfer' => __('accounting.transfer'),
        'reversal' => __('accounting.reversal'),
        default => null,
    };
@endphp

<x-page-head :back="route('accounting.journal.index')" :backLabel="__('accounting.journal')">
    <x-slot:title>
        <span class="force-ltr">{{ $number }}</span>
    </x-slot:title>

    <x-slot:subtitle>
        <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="force-ltr">@format_date($head->date)</span>
            @if ($head->name)
                <span class="text-slate-300">&middot;</span>
                <span>{{ $head->name }}</span>
            @endif
            <span class="inline-flex flex-wrap items-center gap-1.5">
                @if ($reversed)
                    <span class="badge-muted">{{ __('accounting.state_reversed') }}</span>
                @else
                    <span class="badge-success">{{ __('accounting.state_live') }}</span>
                @endif
                @if ($subType)
                    <span class="badge-info">{{ $subType }}</span>
                @endif
            </span>
        </span>
    </x-slot:subtitle>

    <button type="button" onclick="window.print()" class="btn-secondary">
        <x-nav-icon name="printer"/>
        {{ __('lang_v1.print') }}
    </button>

    {{-- Shown only where it leads somewhere: `showJournal()` builds `canReverse`
         from the permission *and* the two conditions the service itself refuses on,
         so a visible button here always works. --}}
    @if ($canReverse)
        <form method="POST" action="{{ route('accounting.journal.reverse', $number) }}"
              data-confirm="{{ __('accounting.confirm_reverse') }}">
            @csrf
            <button type="submit" class="btn-danger">
                <x-nav-icon name="undo"/>
                {{ __('accounting.reverse') }}
            </button>
        </form>
    @endif
</x-page-head>

@if ($reversed)
    <div class="alert-warning mb-6" role="alert">
        <x-nav-icon name="alert"/>
        <div class="min-w-0">
            <p class="font-semibold">{{ __('accounting.state_reversed') }}</p>
            <p class="mt-0.5">{{ __('accounting.reversed_document_note') }}</p>
        </div>
    </div>
@endif

<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-3">
        <x-stat :label="__('accounting.total_debit')" :value="format_currency($debit)" icon="arrow-forward"/>

        <x-stat :label="__('accounting.total_credit')" :value="format_currency($credit)"
                icon="arrow-back"
                :tone="$balanced ? null : 'danger'"
                :hint="$balanced ? __('accounting.books_balanced') : __('accounting.books_unbalanced')"/>

        <x-stat :label="__('accounting.document_lines')" :value="format_quantity($lines->count())" icon="list"/>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-4">

    {{-- ============ The lines ============ --}}
    <x-panel :title="__('accounting.journal_lines')" icon="list"
             :count="$lines->count()" class="lg:col-span-3" flush>
        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.account') }}</th>
                        <th>{{ __('accounting.cost_center') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.debit') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.credit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <td>
                                <a href="{{ route('accounting.accounts.show', $line->chart_of_account_id) }}"
                                   class="cell-link">
                                    {{ $line->chart_of_account->name }}
                                </a>
                                <span class="cell-meta force-ltr">{{ $line->chart_of_account->gl_code }}</span>
                            </td>

                            <td>
                                <span class="cell-primary">{{ or_dash($line->cost_center?->name) }}</span>
                                @if ($line->notes)
                                    <span class="cell-meta">{{ $line->notes }}</span>
                                @endif
                            </td>

                            {{-- Blank, not zero, on the side the line is not on — the
                                 same reading as the account ledger. --}}
                            <td class="cell-numeric">
                                @if ((float) $line->debit > 0)
                                    @format_currency($line->debit)
                                @endif
                            </td>

                            <td class="cell-numeric">
                                @if ((float) $line->credit > 0)
                                    @format_currency($line->credit)
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>

                <tfoot>
                    <tr>
                        <th colspan="2" class="text-end">{{ __('lang_v1.total') }}</th>
                        <td class="cell-numeric font-semibold">@format_currency($debit)</td>
                        <td @class(['cell-numeric', 'font-semibold', 'text-rose-700' => ! $balanced])>
                            @format_currency($credit)
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-panel>

    <div class="grid gap-6 self-start">

        {{-- ============ The paperwork ============ --}}
        <x-panel :title="__('lang_v1.details')" icon="clipboard">
            <x-attr-list :items="[
                'accounting.transaction_number' => $number,
                'lang_v1.date' => format_date($head->date),
                'accounting.reference' => $head->reference,
                'lang_v1.business_location' => $head->business_location->name ?? null,
                'accounting.posted_by' => $head->created_by->user_full_name ?? null,
                'lang_v1.contact' => $head->contact?->name,
                'lang_v1.created_on' => $head->created_at?->format('Y-m-d'),
            ]"/>

            @if ($head->notes)
                <p class="mt-5 border-t border-slate-200 pt-4 text-sm text-slate-600">{{ $head->notes }}</p>
            @endif
        </x-panel>

        {{-- Stated here rather than only in the confirm dialog: someone looking for
             the edit button needs to read this before they decide the screen is
             broken. --}}
        <x-panel :title="__('lang_v1.how_this_works')" icon="info" quiet>
            <p class="text-sm text-slate-600">{{ __('accounting.no_edit_note') }}</p>
        </x-panel>
    </div>
</div>
@endsection
