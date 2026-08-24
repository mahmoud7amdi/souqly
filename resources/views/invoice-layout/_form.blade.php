{{--
    Invoice-layout form, shared by create and edit.

    The table is ~90 columns of label overrides and print toggles, so the form is
    rendered from the controller's grouped field definition
    (InvoiceLayoutController::fieldGroups()) rather than hand-written: one panel
    per group, each delegating to the shared `crud/_form` field renderer so the
    accessibility wiring and error display are identical to every other settings
    screen.

    Expects: $groups, $record (null on create).
--}}
@php $record = $record ?? null; @endphp

<div class="grid gap-6">
    @foreach ($groups as $group)
        <x-panel :title="$group['title']" :icon="$group['icon']">
            <div class="form-grid">
                @include('crud._form', ['fields' => $group['fields'], 'record' => $record])
            </div>
        </x-panel>
    @endforeach
</div>
