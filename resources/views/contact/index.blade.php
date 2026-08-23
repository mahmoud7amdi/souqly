@extends('layouts.app')
@section('title', __('lang_v1.contacts'))
@section('page_title', __('lang_v1.contacts'))

@section('content')

@php
    $isFiltered = request()->filled('search') || request()->filled('contact_status');

    /* Creating from the "all" tab has to pick a type, and customer is the one a
       shop adds fifty times for every supplier. */
    $createType = $type === 'all' ? 'customer' : $type;
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $contacts->total(), ['count' => $contacts->total()])">
    <a href="{{ route('contacts.import.form') }}" class="btn-secondary">
        <x-nav-icon name="upload"/>
        {{ __('lang_v1.import') }}
    </a>
    @can('customer.create')
        <a href="{{ route('contacts.create', ['type' => $createType]) }}" class="btn-primary">
            <x-nav-icon name="user-plus"/>
            {{ __('lang_v1.add') }}
        </a>
    @endcan
</x-page-head>

{{-- Customer / supplier is a change of view, not a filter, so it reads as tabs.
     Underlined tabs rather than a row of filled buttons: three primary-coloured
     pills side by side each claim to be the main action of the page. --}}
<nav class="tab-bar" aria-label="{{ __('lang_v1.type') }}">
    @foreach ([
        'all' => __('lang_v1.all'),
        'customer' => __('lang_v1.customers'),
        'supplier' => __('lang_v1.suppliers'),
    ] as $value => $labelText)
        <a href="{{ route('contacts.index', ['type' => $value]) }}"
           @class(['tab', 'tab-active' => $type === $value])
           @if ($type === $value) aria-current="page" @endif>
            {{ $labelText }}
            @if ($type === $value)
                <span class="tab-count">{{ $contacts->total() }}</span>
            @endif
        </a>
    @endforeach
</nav>

<form method="GET" class="filter-bar">
    <input type="hidden" name="type" value="{{ $type }}">

    <div class="filter-grid">
        <div class="field sm:col-span-2">
            <label for="search" class="label">{{ __('lang_v1.search') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search" placeholder="{{ __('lang_v1.name_mobile_or_id') }}">
            </div>
        </div>

        <div class="field">
            <label for="contact_status" class="label">{{ __('lang_v1.status') }}</label>
            <select id="contact_status" name="contact_status" class="select">
                <option value="">{{ __('lang_v1.all') }}</option>
                <option value="active" @selected(request('contact_status') === 'active')>{{ __('lang_v1.active') }}</option>
                <option value="inactive" @selected(request('contact_status') === 'inactive')>{{ __('lang_v1.inactive') }}</option>
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>
            @if ($isFiltered)
                <a href="{{ route('contacts.index', ['type' => $type]) }}" class="btn-secondary">
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
                <th>{{ __('lang_v1.name') }}</th>
                <th>{{ __('lang_v1.type') }}</th>
                <th>{{ __('lang_v1.mobile') }}</th>
                <th class="th-numeric">{{ __('lang_v1.due') }}</th>
                <th class="th-numeric">{{ __('lang_v1.advance_balance') }}</th>
                <th>{{ __('lang_v1.status') }}</th>
                <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($contacts as $contact)
                @php $due = $dues[$contact->id] ?? 0; @endphp
                <tr>
                    <td>
                        <a href="{{ route('contacts.show', $contact->id) }}" class="cell-link">
                            {{ $contact->full_name_with_business }}
                        </a>
                        <span class="cell-meta force-ltr">{{ $contact->contact_id }}</span>
                    </td>
                    <td><span class="badge-muted">{{ __('lang_v1.'.$contact->type) }}</span></td>
                    <td class="force-ltr">{{ or_dash($contact->mobile) }}</td>
                    {{-- Money owed is the only figure here worth interrupting a scan
                         for, and only when it is not zero. --}}
                    <td @class(['cell-numeric', 'font-semibold text-rose-600' => $due > 0])>
                        @format_currency($due)
                    </td>
                    <td class="cell-numeric">@format_currency($contact->balance)</td>
                    <td>
                        <span class="{{ $contact->contact_status === 'active' ? 'badge-success' : 'badge-muted' }}">
                            {{ __('lang_v1.'.$contact->contact_status) }}
                        </span>
                    </td>
                    <td>
                        <div class="cell-actions">
                            <a href="{{ route('contacts.ledger', $contact->id) }}"
                               class="btn-icon" title="{{ __('lang_v1.ledger') }}"
                               aria-label="{{ __('lang_v1.ledger') }}">
                                <x-nav-icon name="book" :size="4"/>
                            </a>
                            @can('customer.update')
                                <a href="{{ route('contacts.edit', $contact->id) }}"
                                   class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                   aria-label="{{ __('lang_v1.edit') }}">
                                    <x-nav-icon name="edit" :size="4"/>
                                </a>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <x-table-empty :columns="7"
                               :icon="$isFiltered ? 'search' : 'users'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : null">
                    @if ($isFiltered)
                        <a href="{{ route('contacts.index', ['type' => $type]) }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif (auth()->user()->can('customer.create'))
                        <a href="{{ route('contacts.create', ['type' => $createType]) }}" class="btn-primary btn-sm">
                            <x-nav-icon name="user-plus" :size="4"/>
                            {{ __('lang_v1.add') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $contacts->links() }}
</div>
@endsection
