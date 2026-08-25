{{--
    A maintenance job, shared by create and edit.

    Short on purpose. The job is raised in the thirty seconds after somebody notices
    a broken thing, and every field that is not needed then is a field that gets
    skipped or filled in wrongly. So: which asset, how urgent, who is doing it, and
    what is wrong. The reference number generates itself.

    The asset is chosen once and then fixed. Moving a job to a different asset is not
    a correction, it is a different job — `AssetMaintenanceController::rules()` leaves
    `asset_id` out of the update rules for the same reason, so this is not merely a
    display choice.
--}}
@php
    $record = $record ?? null;
    $presetAssetId = $presetAssetId ?? null;
    $isEdit = (bool) $record;

    $selectedAsset = (int) old('asset_id', $record->asset_id ?? $presetAssetId ?? 0);
@endphp

<div class="grid gap-6 lg:grid-cols-3">

    <x-panel :title="__('assetmanagement.job_details')" icon="cog"
             :subtitle="__('assetmanagement.job_details_hint')" class="lg:col-span-2">
        <div class="form-grid">
            <div class="field sm:col-span-2">
                <label for="asset_id" class="label label-required">{{ __('assetmanagement.asset') }}</label>
                @if ($isEdit)
                    {{-- Shown, not offered: the name of the thing being fixed is the
                         single most useful line on this screen, so it stays visible
                         even though it cannot be changed. --}}
                    <p class="input-static">{{ $record->asset->name ?? '' }}
                        <span class="force-ltr">{{ $record->asset->asset_code ?? '' }}</span>
                    </p>
                    <p class="hint">{{ __('assetmanagement.asset_fixed_hint') }}</p>
                @else
                    <select id="asset_id" name="asset_id"
                            @class(['select', 'input-invalid' => $errors->has('asset_id')]) required>
                        <option value="">{{ __('lang_v1.please_select') }}</option>
                        @foreach ($assets as $id => $label)
                            <option value="{{ $id }}" @selected($selectedAsset === (int) $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('asset_id')<p class="field-error">{{ $message }}</p>@enderror
                @endif
            </div>

            <div class="field">
                <label for="status" class="label label-required">{{ __('lang_v1.status') }}</label>
                <select id="status" name="status"
                        @class(['select', 'input-invalid' => $errors->has('status')]) required>
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}"
                                @selected((string) old('status', $record->status ?? 'scheduled') === (string) $key)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('status')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="priority" class="label label-required">{{ __('lang_v1.priority') }}</label>
                <select id="priority" name="priority"
                        @class(['select', 'input-invalid' => $errors->has('priority')]) required>
                    @foreach ($priorities as $key => $label)
                        <option value="{{ $key }}"
                                @selected((string) old('priority', $record->priority ?? 'medium') === (string) $key)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('priority')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field sm:col-span-2">
                <label for="details" class="label">{{ __('assetmanagement.what_is_wrong') }}</label>
                <textarea id="details" name="details" rows="3"
                          @class(['textarea', 'input-invalid' => $errors->has('details')])
                          maxlength="2000"
                          placeholder="{{ __('assetmanagement.what_is_wrong_placeholder') }}">{{ old('details', $record->details ?? '') }}</textarea>
                @error('details')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field sm:col-span-2">
                <label for="maintenance_note" class="label">{{ __('assetmanagement.work_note') }}</label>
                <textarea id="maintenance_note" name="maintenance_note" rows="3"
                          @class(['textarea', 'input-invalid' => $errors->has('maintenance_note')])
                          maxlength="2000"
                          placeholder="{{ __('assetmanagement.work_note_placeholder') }}">{{ old('maintenance_note', $record->maintenance_note ?? '') }}</textarea>
                <p class="hint">{{ __('assetmanagement.work_note_hint') }}</p>
                @error('maintenance_note')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </x-panel>

    <x-panel :title="__('assetmanagement.assignment')" icon="users"
             :subtitle="__('assetmanagement.assignment_hint')" class="self-start">
        <div class="grid gap-5">
            <div class="field">
                <label for="assigned_to" class="label">{{ __('assetmanagement.assigned_to') }}</label>
                <select id="assigned_to" name="assigned_to"
                        @class(['select', 'input-invalid' => $errors->has('assigned_to')])>
                    @foreach ($users as $id => $name)
                        <option value="{{ $id }}"
                                @selected((string) old('assigned_to', $record->assigned_to ?? '') === (string) $id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
                <p class="hint">{{ __('assetmanagement.assigned_to_hint') }}</p>
                @error('assigned_to')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="maintenance_ref" class="label">{{ __('lang_v1.reference_no') }}</label>
                <input id="maintenance_ref" name="maintenance_ref"
                       @class(['input', 'force-ltr', 'input-invalid' => $errors->has('maintenance_ref')])
                       value="{{ old('maintenance_ref', $record->maitenance_id ?? '') }}" maxlength="255">
                <p class="hint">{{ __('assetmanagement.job_ref_hint') }}</p>
                @error('maintenance_ref')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>
    </x-panel>
</div>
