@extends('layouts.app')
@section('title', __('lang_v1.expense_categories'))
@section('page_title', __('lang_v1.expense_categories'))

@section('content')

@php
    $canAdd = auth()->user()->can('expense.add');
    $canEdit = auth()->user()->can('expense.edit');
    $canDelete = auth()->user()->can('expense.delete');
    $showActions = $canEdit || $canDelete;
@endphp

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $parents->total(), ['count' => $parents->total()])">
    @if (Route::has('expenses.index'))
        <a href="{{ route('expenses.index') }}" class="btn-secondary">
            <x-nav-icon name="receipt" :size="4"/>
            {{ __('lang_v1.expenses') }}
        </a>
    @endif

    @if ($canAdd)
        <a href="{{ route('expense-categories.create') }}" class="btn-primary">
            <x-nav-icon name="plus"/>
            {{ __('lang_v1.add_category') }}
        </a>
    @endif
</x-page-head>

<div class="section-head">
    <div class="section-head-text">
        <p class="section-eyebrow">{{ __('lang_v1.two_levels') }}</p>
        <h2 class="section-title">{{ __('lang_v1.categories_and_their_subs') }}</h2>
        <p class="section-desc">{{ __('lang_v1.expense_category_depth_note') }}</p>
    </div>
</div>

{{-- Children are rendered as extra rows under their parent rather than as a
     nested table or an expandable tree. The depth is fixed at two, so a tree
     widget would be machinery for a shape that cannot grow — and a flat table
     with indented rows stays sortable, printable and readable at a glance. --}}
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.category_name') }}</th>
                <th>{{ __('lang_v1.category_code') }}</th>
                <th class="th-numeric">{{ __('lang_v1.expenses') }}</th>
                @if ($showActions)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($parents as $parent)
                <tr>
                    <td>
                        <span class="cell-primary">{{ $parent->name }}</span>
                        @if ($parent->sub_categories->isNotEmpty())
                            <span class="cell-meta">
                                {{ trans_choice('lang_v1.sub_category_count',
                                    $parent->sub_categories->count(),
                                    ['count' => $parent->sub_categories->count()]) }}
                            </span>
                        @endif
                    </td>
                    <td><span class="force-ltr">{{ or_dash($parent->code) }}</span></td>
                    <td class="cell-numeric">{{ $usage[$parent->id] ?? 0 }}</td>

                    @if ($showActions)
                        <td>
                            <div class="cell-actions">
                                @if ($canEdit)
                                    <a href="{{ route('expense-categories.edit', $parent->id) }}"
                                       class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                       aria-label="{{ __('lang_v1.edit') }}">
                                        <x-nav-icon name="edit" :size="4"/>
                                    </a>
                                @endif

                                {{-- Offered even when it will be refused: the
                                     refusal names the reason (children, or expenses
                                     still pointing here), and hiding the button
                                     would leave a user guessing why a category
                                     cannot go. --}}
                                @if ($canDelete)
                                    <form method="POST"
                                          action="{{ route('expense-categories.destroy', $parent->id) }}"
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

                @foreach ($parent->sub_categories as $child)
                    <tr>
                        <td>
                            {{-- Indent by padding on the cell, not by a nested
                                 table: the columns must stay aligned with the
                                 parent's or the code and count columns wander. --}}
                            <span class="flex items-center gap-2 ps-6 text-slate-700">
                                <x-nav-icon name="dot" :size="3" class="text-slate-400"/>
                                {{ $child->name }}
                            </span>
                        </td>
                        <td><span class="force-ltr">{{ or_dash($child->code) }}</span></td>
                        <td class="cell-numeric">{{ $usage[$child->id] ?? 0 }}</td>

                        @if ($showActions)
                            <td>
                                <div class="cell-actions">
                                    @if ($canEdit)
                                        <a href="{{ route('expense-categories.edit', $child->id) }}"
                                           class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                           aria-label="{{ __('lang_v1.edit') }}">
                                            <x-nav-icon name="edit" :size="4"/>
                                        </a>
                                    @endif

                                    @if ($canDelete)
                                        <form method="POST"
                                              action="{{ route('expense-categories.destroy', $child->id) }}"
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
                @endforeach
            @empty
                <x-table-empty :columns="$showActions ? 4 : 3"
                               icon="folder"
                               :title="__('lang_v1.nothing_here_yet')"
                               :text="__('lang_v1.categories_group_your_spending')">
                    @if ($canAdd)
                        <a href="{{ route('expense-categories.create') }}" class="btn-primary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('lang_v1.add_category') }}
                        </a>
                    @endif
                </x-table-empty>
            @endforelse
        </tbody>
    </table>

    {{ $parents->links() }}
</div>
@endsection
