{{--
    Stock count header, shared by create and edit.

    Deliberately short: opening a count asks for three things and nothing else.
    The work is the counting, and a long form standing between the person and the
    shelves is how you end up with counts recorded on paper and typed in later.

    The branch is the one field that stops being editable, and only once part of
    the count has posted — see InventoryCountService::update() for why.
--}}
@php
    $record = $record ?? null;
    $branchLocked = $branchLocked ?? false;

    $selectedBranch = (int) old('branch_id', $record->branch_id ?? array_key_first($locations));
@endphp

<div class="grid gap-6 lg:grid-cols-3">

    <x-panel :title="__('lang_v1.count_details')" icon="clipboard" class="lg:col-span-2">
        <div class="form-grid">
            <div class="field">
                <label for="branch_id" class="label label-required">{{ __('lang_v1.business_location') }}</label>
                <select id="branch_id" name="branch_id"
                        @class(['select', 'input-invalid' => $errors->has('branch_id')])
                        @disabled($branchLocked) required>
                    @foreach ($locations as $id => $name)
                        <option value="{{ $id }}" @selected($selectedBranch == $id)>{{ $name }}</option>
                    @endforeach
                </select>
                @if ($branchLocked)
                    {{-- A disabled select submits nothing, so the value has to
                         travel separately or `update` would fail validation on a
                         field the user was never allowed to change. --}}
                    <input type="hidden" name="branch_id" value="{{ $selectedBranch }}">
                    <p class="hint">{{ __('lang_v1.branch_locked_after_posting') }}</p>
                @endif
                @error('branch_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="end_date" class="label">{{ __('lang_v1.count_end_date') }}</label>
                <input type="date" id="end_date" name="end_date"
                       @class(['input', 'input-invalid' => $errors->has('end_date')])
                       value="{{ old('end_date', $record?->end_date?->format('Y-m-d')) }}">
                <p class="hint">{{ __('lang_v1.count_end_date_hint') }}</p>
                @error('end_date')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field sm:col-span-2">
                <label for="name" class="label label-required">{{ __('lang_v1.count_name') }}</label>
                <input id="name" name="name"
                       @class(['input', 'input-invalid' => $errors->has('name')])
                       value="{{ old('name', $record->name ?? '') }}"
                       placeholder="{{ __('lang_v1.count_name_placeholder') }}"
                       maxlength="255" required>
                <p class="hint">{{ __('lang_v1.count_name_hint') }}</p>
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </x-panel>

    {{-- The two directions a count can move stock are the thing nobody expects,
         so they are stated before the count is opened rather than discovered at
         closing time. --}}
    <x-panel :title="__('lang_v1.how_this_works')" icon="info" class="self-start" quiet>
        <ul class="grid gap-3 text-sm text-slate-600">
            <li>{{ __('lang_v1.stock_count_note_two_directions') }}</li>
            <li>{{ __('lang_v1.stock_count_note_book_read_now') }}</li>
            <li>{{ __('lang_v1.stock_count_note_close_once') }}</li>
        </ul>
    </x-panel>
</div>
