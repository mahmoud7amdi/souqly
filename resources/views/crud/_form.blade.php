{{--
    Shared field renderer for the generic CRUD screens.

    Each field is described by the controller's formViewData() as
    ['name' => …, 'type' => …, 'label' => …, 'required' => bool, 'hint' => …,
     'options' => [], 'placeholder' => …, 'width' => 'full'].

    Accessibility is wired here once rather than per screen: an invalid control
    gets aria-invalid plus aria-describedby pointing at its own error text, so a
    screen reader announces *why* a field was rejected instead of only that it
    was. Nine settings screens inherit that from this one file.
--}}
@php $record = $record ?? null; @endphp

@foreach ($fields as $field)
    @php
        $name = $field['name'];
        $type = $field['type'] ?? 'text';
        $value = old($name, $record->{$name} ?? '');
        $invalid = $errors->has($name);
        $required = $field['required'] ?? false;
        $hint = $field['hint'] ?? null;

        /* Ids for the description elements, so aria-describedby can reference
           whichever of them actually rendered. */
        $errorId = $name.'-error';
        $hintId = $name.'-hint';
        $describedBy = collect([
            $invalid ? $errorId : null,
            $hint ? $hintId : null,
        ])->filter()->implode(' ');

        /* A textarea earns the full row; everything else shares the grid. */
        $spanFull = ($field['width'] ?? null) === 'full' || $type === 'textarea';
    @endphp

    <div @class(['field', 'sm:col-span-2' => $spanFull])>
        @if ($type !== 'checkbox')
            <label for="{{ $name }}" @class(['label', 'label-required' => $required])>
                {{ $field['label'] }}
            </label>
        @endif

        @if ($type === 'textarea')
            <textarea id="{{ $name }}" name="{{ $name }}" rows="3"
                      @class(['textarea', 'input-invalid' => $invalid])
                      @if ($required) required @endif
                      @if ($invalid) aria-invalid="true" @endif
                      @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                      @if (! empty($field['placeholder'])) placeholder="{{ $field['placeholder'] }}" @endif
            >{{ $value }}</textarea>

        @elseif ($type === 'select')
            <select id="{{ $name }}" name="{{ $name }}"
                    @class(['select', 'input-invalid' => $invalid])
                    @if ($required) required @endif
                    @if ($invalid) aria-invalid="true" @endif
                    @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
                @foreach ($field['options'] ?? [] as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>
                        {{ $optionLabel }}
                    </option>
                @endforeach
            </select>

        @elseif ($type === 'checkbox')
            {{-- The whole row is the target, not just the 16px box. --}}
            <label class="checkbox-row">
                <input type="checkbox" id="{{ $name }}" name="{{ $name }}" value="1"
                       class="checkbox"
                       @checked((bool) $value)
                       @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
                <span class="checkbox-label">
                    {{ $field['label'] }}
                    @if ($hint)
                        <span id="{{ $hintId }}" class="checkbox-hint">{{ $hint }}</span>
                    @endif
                </span>
            </label>

        @elseif ($type === 'number')
            {{-- inputmode=decimal keeps the numeric keypad on mobile; the JS
                 layer converts Arabic-Indic digits to ASCII on input. --}}
            <input type="text" inputmode="decimal" id="{{ $name }}" name="{{ $name }}"
                   value="{{ $value }}"
                   @class(['input-numeric', 'input-invalid' => $invalid])
                   @if ($required) required @endif
                   @if ($invalid) aria-invalid="true" @endif
                   @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>

        @else
            <input type="{{ $type }}" id="{{ $name }}" name="{{ $name }}"
                   value="{{ $value }}"
                   @class(['input', 'input-invalid' => $invalid])
                   @if ($required) required @endif
                   @if ($invalid) aria-invalid="true" @endif
                   @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
                   @if (! empty($field['placeholder'])) placeholder="{{ $field['placeholder'] }}" @endif>
        @endif

        {{-- Checkboxes render their own hint next to the label above. --}}
        @if ($hint && $type !== 'checkbox')
            <p id="{{ $hintId }}" class="hint">{{ $hint }}</p>
        @endif

        @error($name)
            <p id="{{ $errorId }}" class="field-error">{{ $message }}</p>
        @enderror
    </div>
@endforeach
