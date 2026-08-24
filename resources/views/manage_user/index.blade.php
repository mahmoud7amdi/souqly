@extends('layouts.app')
@section('title', __('lang_v1.users'))
@section('page_title', __('lang_v1.users'))

@section('content')

@php $isFiltered = request()->filled('search'); @endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $users->total(), ['count' => $users->total()])">
    @if ($canCreate)
        <a href="{{ route('users.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.add') }}
        </a>
    @endif
</x-page-head>

<form method="GET" class="filter-bar">
    <div class="flex flex-wrap items-end gap-3">
        <div class="field min-w-56 flex-1">
            <label for="search" class="label">{{ __('lang_v1.search') }}</label>
            <div class="input-search-wrap">
                <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                <input type="search" id="search" name="search" value="{{ request('search') }}"
                       class="input-search" placeholder="{{ __('lang_v1.name') }}">
            </div>
        </div>

        <button type="submit" class="btn-primary">
            <x-nav-icon name="filter"/>
            {{ __('lang_v1.apply') }}
        </button>

        @if ($isFiltered)
            <a href="{{ route('users.index') }}" class="btn-secondary">
                <x-nav-icon name="x" :size="4"/>
                {{ __('lang_v1.reset') }}
            </a>
        @endif
    </div>
</form>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.name') }}</th>
                <th>{{ __('lang_v1.username') }}</th>
                <th>{{ __('lang_v1.role') }}</th>
                <th>{{ __('lang_v1.status') }}</th>
                @if ($canUpdate || $canDelete)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr>
                    <td class="cell-primary">
                        {{ $user->user_full_name }}
                        @if ($user->id === auth()->id())
                            <span class="badge-muted">{{ __('lang_v1.you') }}</span>
                        @endif
                    </td>
                    <td class="force-ltr">{{ $user->username }}</td>
                    <td>{{ $user->role_name ?? '—' }}</td>
                    <td>
                        @php
                            $tone = match ($user->status) {
                                'active' => 'badge-success',
                                'inactive' => 'badge-warning',
                                default => 'badge-danger',
                            };
                        @endphp
                        <span class="{{ $tone }}">{{ __('lang_v1.'.$user->status) }}</span>
                    </td>

                    @if ($canUpdate || $canDelete)
                        <td>
                            <div class="cell-actions">
                                @if ($canUpdate)
                                    <a href="{{ route('users.edit', $user->id) }}"
                                       class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                       aria-label="{{ __('lang_v1.edit') }}">
                                        <x-nav-icon name="edit" :size="4"/>
                                    </a>
                                @endif

                                @if ($canDelete && $user->id !== auth()->id())
                                    <form method="POST" action="{{ route('users.destroy', $user->id) }}"
                                          data-confirm="{{ __('lang_v1.confirm_delete') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-icon-danger"
                                                title="{{ __('lang_v1.delete') }}"
                                                aria-label="{{ __('lang_v1.delete') }}">
                                            <x-nav-icon name="trash" :size="4"/>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <x-table-empty :columns="4 + ($canUpdate || $canDelete ? 1 : 0)"
                               :icon="$isFiltered ? 'search' : 'users'"
                               :title="$isFiltered ? __('lang_v1.no_records_found') : __('lang_v1.nothing_here_yet')"
                               :text="$isFiltered ? __('lang_v1.nothing_matches_filters') : null">
                    @if ($isFiltered)
                        <a href="{{ route('users.index') }}" class="btn-secondary btn-sm">
                            {{ __('lang_v1.clear_filters') }}
                        </a>
                    @elseif ($canCreate)
                        <a href="{{ route('users.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.add') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $users->links() }}
</div>
@endsection
