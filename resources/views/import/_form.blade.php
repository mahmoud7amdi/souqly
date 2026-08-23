{{--
    Shared body of the two import screens.

    Products and contacts had a near-identical 57-line view each, differing only
    in two route names and one extra hint — so a fix to the upload panel was two
    edits with no way to notice when only one was made.

    Expects:
      $action        POST target for the upload
      $templateRoute GET link to the blank spreadsheet
      $columns       ordered column names the file must match
      $hints         optional extra notes for the column panel (already translated)
--}}
@php
    $hints = $hints ?? [];
@endphp

<form method="POST" action="{{ $action }}" enctype="multipart/form-data">
    @csrf

    <div class="grid gap-6 lg:grid-cols-3">

        <x-panel :title="__('lang_v1.upload_file')" icon="upload" class="lg:col-span-2">
            {{-- The template belongs here, beside the field, not in the commit bar:
                 you download it *before* choosing a file, and sitting next to
                 Import made it read as a second way to submit. --}}
            <x-slot:actions>
                <a href="{{ $templateRoute }}" class="btn-secondary">
                    <x-nav-icon name="download" :size="4"/>
                    {{ __('lang_v1.download_template') }}
                </a>
            </x-slot:actions>

            <div class="grid gap-5">
                <div class="field">
                    <label for="file" class="label label-required">{{ __('lang_v1.file') }}</label>
                    <input id="file" name="file" type="file" accept=".xlsx,.xls,.csv"
                           @class(['input', 'input-invalid' => $errors->has('file')]) required>
                    <p class="hint">{{ __('lang_v1.import_accepted_formats') }}</p>
                    @error('file')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="alert-info">
                    <span>{{ __('lang_v1.import_all_or_nothing') }}</span>
                </div>

                {{-- Row-by-row failures from the last attempt. Nothing was written —
                     the import is one transaction — so this is a worklist, not a
                     report of partial damage. --}}
                @if (session('import_errors'))
                    <div class="alert-danger">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ __('lang_v1.import_failed') }}</p>
                            <ul class="mt-1 list-disc space-y-1 ps-5">
                                @foreach (session('import_errors') as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.expected_columns')" icon="list"
                 :count="count($columns)" class="self-start">
            {{-- text-end on the number holds in both directions: the number box is
                 first in the flex row, so its content should hug the side the name
                 is on — right in English, left in Arabic. --}}
            <ol class="grid gap-1.5 text-sm text-slate-600">
                @foreach ($columns as $index => $column)
                    <li class="flex gap-2">
                        <span class="w-5 shrink-0 text-end text-xs text-slate-400">{{ $index + 1 }}</span>
                        <code class="force-ltr min-w-0 break-words">{{ $column }}</code>
                    </li>
                @endforeach
            </ol>

            <div class="mt-4 grid gap-2">
                <p class="hint mt-0">{{ __('lang_v1.import_column_order_hint') }}</p>
                @foreach ($hints as $hint)
                    <p class="hint mt-0">{{ $hint }}</p>
                @endforeach
            </div>
        </x-panel>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn-primary">
            <x-nav-icon name="upload"/>
            {{ __('lang_v1.import') }}
        </button>
    </div>
</form>
