{{--
    Variation template form. Values are edited as one textarea (one per line)
    rather than a JS row-builder — fewer moving parts, and it round-trips
    cleanly in RTL.
--}}
@php
    $record = $record ?? null;
    $existing = $record
        ? $record->values->pluck('name')->implode("\n")
        : '';
@endphp

<div class="field">
    <label for="name" class="label label-required">{{ __('lang_v1.name') }}</label>
    <input id="name" name="name" @class(['input', 'input-invalid' => $errors->has('name')])
           value="{{ old('name', $record->name ?? '') }}" required
           placeholder="{{ __('lang_v1.variation_name_placeholder') }}">
    @error('name')<p class="field-error">{{ $message }}</p>@enderror
</div>

<div class="field">
    <label for="values_text" class="label label-required">{{ __('lang_v1.values') }}</label>

    {{-- The error is registered against `values`, the array the controller
         validates, not `values_text`, the box it was typed into. --}}
    <textarea id="values_text" name="values_text" rows="6"
              @class(['textarea', 'input-invalid' => $errors->has('values')])
              placeholder="{{ __('lang_v1.values_placeholder') }}">{{ old('values_text', $existing) }}</textarea>
    <p class="hint">{{ __('lang_v1.values_one_per_line') }}</p>
    @error('values')<p class="field-error">{{ $message }}</p>@enderror
</div>

{{-- Split into values[] on submit so the controller receives an array. --}}
<script>
    document.currentScript.closest('form').addEventListener('submit', function (event) {
        const form = event.target;
        form.querySelectorAll('input[name="values[]"]').forEach((node) => node.remove());

        form.querySelector('#values_text').value
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean)
            .forEach((value) => {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'values[]';
                hidden.value = value;
                form.appendChild(hidden);
            });
    });
</script>
