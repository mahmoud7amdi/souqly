{{--
    Add / edit an expense category.

    Three fields and one rule that needs saying out loud: the tree is two levels
    deep and cannot be deeper, because `transactions` has exactly two columns for
    it. The parent list is therefore main categories only, and the hint says so
    rather than leaving a user to discover it from a 422.

    Expects: category, parents.
--}}
@php
    $isEdit = ! empty($category);

    /* A category that already has children cannot itself become a child — the
       controller refuses it, so the form says why before the attempt. The count
       comes from `edit()`; `create()` has no category and so no children. */
    $hasChildren = $isEdit && ($category->sub_categories_count ?? 0) > 0;
@endphp

<form method="POST"
      action="{{ $isEdit ? route('expense-categories.update', $category->id) : route('expense-categories.store') }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="max-w-2xl">
        <x-panel :title="__('lang_v1.category_details')" icon="folder">
            <div class="form-grid">
                <div class="field sm:col-span-2">
                    <label for="name" class="label label-required">
                        {{ __('lang_v1.category_name') }}
                    </label>
                    <input id="name" name="name" value="{{ old('name', $category->name ?? '') }}"
                           class="input @error('name') input-invalid @enderror" required autofocus>
                    @error('name')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="code" class="label">{{ __('lang_v1.category_code') }}</label>
                    <input id="code" name="code" dir="ltr"
                           value="{{ old('code', $category->code ?? '') }}"
                           class="input @error('code') input-invalid @enderror">
                    @error('code')
                        <p class="field-error">{{ $message }}</p>
                    @else
                        <p class="hint">{{ __('lang_v1.category_code_hint') }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="parent_id" class="label">{{ __('lang_v1.parent_category') }}</label>
                    {{-- A disabled select submits nothing, which would post an
                         absent `parent_id` and null it out. It is already null for
                         a category with children (two levels, so a child cannot
                         have any), but a form should not depend on that to avoid
                         rewriting a field the user was not offered. --}}
                    <input type="hidden" name="parent_id" value="{{ $category->parent_id ?? '' }}">
                    <select id="parent_id" name="parent_id" class="select"
                            @disabled($hasChildren)>
                        @foreach ($parents as $id => $name)
                            <option value="{{ $id }}"
                                @selected(old('parent_id', $category->parent_id ?? null) == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('parent_id')
                        <p class="field-error">{{ $message }}</p>
                    @else
                        <p class="hint">
                            {{ $hasChildren
                                ? __('lang_v1.cannot_nest_category_with_children')
                                : __('lang_v1.leave_as_main_or_pick_a_parent') }}
                        </p>
                    @enderror
                </div>
            </div>
        </x-panel>
    </div>

    <div class="form-actions">
        <span class="form-actions-spacer">{{ __('lang_v1.expense_category_depth_note') }}</span>

        <a href="{{ route('expense-categories.index') }}" class="btn-secondary">
            {{ __('lang_v1.cancel') }}
        </a>

        <button type="submit" class="btn-primary">
            <x-nav-icon name="save" :size="4"/>
            {{ $isEdit ? __('lang_v1.update') : __('lang_v1.save') }}
        </button>
    </div>
</form>
