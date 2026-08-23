@extends('layouts.app')
@section('title', __('lang_v1.ledger'))
@section('page_title', $contact->full_name_with_business.' — '.__('lang_v1.ledger'))

@section('content')

@php
    $debitTotal = collect($entries)->sum('debit');
    $creditTotal = collect($entries)->sum('credit');
    $closingBalance = empty($entries) ? $openingBalance : end($entries)['balance'];
@endphp

{{-- This screen is also a document. The layout's header, sidebar and footer are
     all .no-print, and .page-back and .filter-bar drop out too, so what survives
     on paper is exactly a statement: business name, who it is for, the period,
     the table. That is why the letterhead lives in the page head rather than in a
     card header — a card header would print inside a box. --}}
<x-page-head :title="session('business.name')"
             :back="route('contacts.show', $contact->id)"
             :backLabel="__('lang_v1.back_to_contact')">
    <x-slot:subtitle>
        {{ __('lang_v1.statement_for') }}: {{ $contact->full_name_with_business }}
        <span class="text-slate-300">&middot;</span>
        <span class="force-ltr">@format_date($start) — @format_date($end)</span>
    </x-slot:subtitle>

    <button type="button" onclick="window.print()" class="btn-secondary">
        <x-nav-icon name="printer"/>
        {{ __('lang_v1.print') }}
    </button>
</x-page-head>

<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="field">
            <label for="start_date" class="label">{{ __('lang_v1.from') }}</label>
            <input type="date" id="start_date" name="start_date" value="{{ $start }}" class="input">
        </div>

        <div class="field">
            <label for="end_date" class="label">{{ __('lang_v1.to') }}</label>
            <input type="date" id="end_date" name="end_date" value="{{ $end }}" class="input">
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
        </div>
    </div>
</form>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.date') }}</th>
                <th>{{ __('lang_v1.reference_no') }}</th>
                <th>{{ __('lang_v1.description') }}</th>
                <th class="th-numeric">{{ __('lang_v1.debit') }}</th>
                <th class="th-numeric">{{ __('lang_v1.credit') }}</th>
                <th class="th-numeric">{{ __('lang_v1.balance') }}</th>
            </tr>
        </thead>
        <tbody>
            {{-- Carried-forward line, so the running balance in the last column
                 means something from the first row onward. --}}
            <tr class="bg-slate-50">
                <td colspan="5" class="font-medium">{{ __('lang_v1.opening_balance') }}</td>
                <td class="cell-numeric font-semibold">@format_currency($openingBalance)</td>
            </tr>

            @forelse ($entries as $entry)
                <tr>
                    <td class="whitespace-nowrap">@format_date($entry['date'])</td>
                    <td class="force-ltr">{{ $entry['reference'] }}</td>
                    <td><span class="badge-muted">{{ __('lang_v1.'.$entry['type']) }}</span></td>
                    {{-- Every row touches exactly one of the two columns, so the
                         other gets a muted dash rather than a zero: a grid of 0.00
                         reads as "twelve payments of nothing". --}}
                    <td class="cell-numeric">
                        {{ or_dash($entry['debit'] > 0 ? format_currency($entry['debit']) : null) }}
                    </td>
                    <td class="cell-numeric">
                        {{ or_dash($entry['credit'] > 0 ? format_currency($entry['credit']) : null) }}
                    </td>
                    <td class="cell-numeric font-medium">@format_currency($entry['balance'])</td>
                </tr>
            @empty
                <x-table-empty :columns="6" icon="book"
                               :title="__('lang_v1.no_records_found')"
                               :text="__('lang_v1.nothing_matches_filters')"/>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">{{ __('lang_v1.closing_balance') }}</td>
                <td class="cell-numeric">@format_currency($debitTotal)</td>
                <td class="cell-numeric">@format_currency($creditTotal)</td>
                <td class="cell-numeric">@format_currency($closingBalance)</td>
            </tr>
        </tfoot>
    </table>
</div>
@endsection
