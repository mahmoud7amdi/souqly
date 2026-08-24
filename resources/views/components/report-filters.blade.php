{{--
    The filter bar shared by every report.

    Reports differ in what they filter by but not in how it looks or how it
    submits, so the chrome lives here and each screen opts in to the fields it
    actually honours via `fields`. That opt-in is the point: a date picker on a
    screen that ignores dates is worse than no date picker, because the user
    changes it, sees nothing move, and stops trusting the numbers.

    The date inputs are pre-filled with the *effective* range rather than left
    blank when absent. The current-month default is what makes a report render
    with no query string at all; showing it means the reader can see which period
    they are looking at instead of having to infer it from an empty box.

    `no-print` because a printed report should carry its figures and its period,
    not the controls used to pick them.
--}}
@props([
    'report',
    'action',
    'range' => null,
    'fields' => [],
    'locations' => [],
    'categories' => [],
    'brands' => [],
])

@php
    $has = fn (string $field) => in_array($field, $fields, true);

    // Which query keys this particular bar owns — used for the reset button, so
    // "reset" never appears on a screen that has nothing to reset.
    $keys = array_merge(
        $range ? ['start_date', 'end_date'] : [],
        $has('location') ? ['location_id'] : [],
        $has('category') ? ['category_id'] : [],
        $has('brand') ? ['brand_id'] : [],
        $has('expense_category') ? ['expense_category_id'] : [],
    );

    $isFiltered = collect($keys)->contains(fn ($key) => request()->filled($key));

    // Carries the visible filters into the download, so the file and the screen
    // can never show different numbers.
    $exportUrl = route('reports.export', ['report' => $report] + request()->query());
@endphp

<form method="GET" action="{{ $action }}" class="filter-bar no-print">
    <div class="filter-grid">
        @if ($range)
            <div class="field">
                <label for="start_date" class="label">{{ __('lang_v1.from') }}</label>
                <input type="date" id="start_date" name="start_date"
                       value="{{ request('start_date', $range['start']) }}" class="input">
            </div>

            <div class="field">
                <label for="end_date" class="label">{{ __('lang_v1.to') }}</label>
                <input type="date" id="end_date" name="end_date"
                       value="{{ request('end_date', $range['end']) }}" class="input">
            </div>
        @endif

        @if ($has('location'))
            <div class="field">
                <label for="location_id" class="label">{{ __('lang_v1.business_location') }}</label>
                <select id="location_id" name="location_id" class="select">
                    @foreach ($locations as $id => $name)
                        <option value="{{ $id }}" @selected(request('location_id') == $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($has('category'))
            <div class="field">
                <label for="category_id" class="label">{{ __('lang_v1.category') }}</label>
                <select id="category_id" name="category_id" class="select">
                    @foreach ($categories as $id => $name)
                        <option value="{{ $id }}" @selected(request('category_id') == $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($has('expense_category'))
            <div class="field">
                <label for="expense_category_id" class="label">{{ __('lang_v1.expense_category') }}</label>
                {{-- Matches either the category or the sub-category column, so
                     picking a parent includes everything under it. --}}
                <select id="expense_category_id" name="expense_category_id" class="select">
                    @foreach ($categories as $id => $name)
                        <option value="{{ $id }}" @selected(request('expense_category_id') == $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($has('brand'))
            <div class="field">
                <label for="brand_id" class="label">{{ __('lang_v1.brand') }}</label>
                <select id="brand_id" name="brand_id" class="select">
                    @foreach ($brands as $id => $name)
                        <option value="{{ $id }}" @selected(request('brand_id') == $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div class="flex flex-wrap items-end gap-2">
            <button type="submit" class="btn-primary">
                <x-nav-icon name="filter"/>
                {{ __('lang_v1.apply') }}
            </button>

            @if ($isFiltered)
                <a href="{{ $action }}" class="btn-secondary">
                    <x-nav-icon name="x" :size="4"/>
                    {{ __('lang_v1.reset') }}
                </a>
            @endif

            @can('view_export_buttons')
                <a href="{{ $exportUrl }}" class="btn-secondary">
                    <x-nav-icon name="download" :size="4"/>
                    {{ __('lang_v1.export_to_excel') }}
                </a>
            @endcan
        </div>
    </div>
</form>
