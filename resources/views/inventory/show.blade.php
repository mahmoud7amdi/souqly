@extends('layouts.app')
@section('title', $record->name)
@section('page_title', __('lang_v1.stock_counts').' — '.$record->name)

@section('content')

@php
    $isClosed = (bool) $record->status;
    $canClose = ! $isClosed && auth()->user()->can('inventorymanagement.close');
    $canEdit = ! $isClosed && auth()->user()->can('inventorymanagement.update');

    $columnCount = 5 + (int) $canEdit;
@endphp

<x-page-head :title="$record->name" :back="route('inventory.index')"
             :backLabel="__('lang_v1.stock_counts')">
    <x-slot:subtitle>
        <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <span>{{ or_dash($record->branch->name ?? null) }}</span>
            <span class="text-slate-300">&middot;</span>
            <span class="force-ltr">@format_date($record->created_at)</span>
            <span class="ms-1 inline-flex items-center gap-1.5">
                @if ($isClosed)
                    <span class="badge-success">{{ __('lang_v1.closed') }}</span>
                @else
                    <span class="badge-warning">{{ __('lang_v1.open') }}</span>
                @endif
            </span>
        </span>
    </x-slot:subtitle>

    @if ($canEdit)
        <a href="{{ route('inventory.edit', $record->id) }}" class="btn-secondary">
            <x-nav-icon name="edit"/>
            {{ __('lang_v1.edit') }}
        </a>
    @endif

    {{-- The only irreversible button on the screen, so it is the only one that
         asks — and it is disabled outright while there is nothing to post, since
         closing an empty count would produce a closed count that says nothing. --}}
    @if ($canClose)
        <form method="POST" action="{{ route('inventory.close', $record->id) }}"
              data-confirm="{{ __('lang_v1.confirm_close_and_post') }}">
            @csrf
            <button type="submit" class="btn-primary" @disabled($summary['pending'] === 0)>
                <x-nav-icon name="check-circle"/>
                {{ __('lang_v1.close_and_post') }}
            </button>
        </form>
    @endif
</x-page-head>

@if ($isClosed)
    <div class="alert-info mb-5" role="note">
        <x-nav-icon name="check-circle"/>
        <div class="min-w-0">
            <p class="font-semibold">{{ __('lang_v1.count_is_closed') }}</p>
            <p class="mt-0.5">{{ __('lang_v1.count_is_closed_desc') }}</p>
        </div>
    </div>
@endif

{{-- Surplus and shortage are kept apart rather than netted. A count that found
     40 extra of one item and 40 missing of another is not a count that balanced —
     it is two problems, and one number would hide both. --}}
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('lang_v1.counted_items')"
                :value="format_quantity($summary['lines'])"
                icon="clipboard"/>

        <x-stat :label="__('lang_v1.surplus')"
                :value="format_quantity($summary['surplus_qty'])"
                icon="plus"
                :tone="$summary['surplus_qty'] > 0 ? 'success' : null"/>

        <x-stat :label="__('lang_v1.shortage')"
                :value="format_quantity($summary['shortage_qty'])"
                icon="minus"
                :tone="$summary['shortage_qty'] > 0 ? 'danger' : null"/>

        <x-stat :label="__('lang_v1.posted')"
                :value="format_quantity($summary['posted'])"
                icon="check-circle"
                :hint="__('lang_v1.lines_pending', ['count' => format_quantity($summary['pending'])])"/>
    </div>
</div>

@if ($canCount)
    <x-panel :title="__('lang_v1.record_a_count')" icon="search" class="mb-6">
        <form method="POST" action="{{ route('inventory.lines.store', $record->id) }}" id="count-form">
            @csrf
            <input type="hidden" name="variation_id" id="variation_id" value="{{ old('variation_id') }}">

            <div class="form-grid-3">
                <div class="field sm:col-span-2 lg:col-span-2">
                    <label for="product-search" class="label label-required">{{ __('lang_v1.product') }}</label>
                    <div class="input-search-wrap">
                        <span class="input-search-icon"><x-nav-icon name="search" :size="4"/></span>
                        <input id="product-search"
                               @class(['input-search', 'input-invalid' => $errors->has('variation_id')])
                               placeholder="{{ __('lang_v1.search_product_to_add') }}"
                               autocomplete="off">
                    </div>

                    {{-- The book quantity is shown beside the chosen product, not
                         instead of the counted one: seeing "the system thinks 12"
                         while typing 9 is what catches a scan of the wrong
                         variation, which is the mistake this screen actually
                         makes. --}}
                    <p class="hint" id="picked-line" hidden>
                        <span id="picked-name" class="font-medium text-slate-700"></span>
                        <span id="picked-sku" class="force-ltr"></span>
                        <span id="picked-stock"></span>
                    </p>
                    @error('variation_id')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="counted" class="label label-required">{{ __('lang_v1.counted_quantity') }}</label>
                    <input id="counted" name="counted" class="input-numeric" inputmode="decimal"
                           value="{{ old('counted') }}" required>
                    @error('counted')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="mt-4 flex justify-end">
                <button type="submit" class="btn-primary" id="count-submit" disabled>
                    <x-nav-icon name="plus"/>
                    {{ __('lang_v1.add') }}
                </button>
            </div>
        </form>
    </x-panel>
@endif

<div class="grid gap-6 lg:grid-cols-4">

    <x-panel :title="__('lang_v1.counted_items')" icon="box" :count="$summary['lines']"
             class="lg:col-span-3" flush>
        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.product') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.book_quantity') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.counted_quantity') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.difference') }}</th>
                        <th>{{ __('lang_v1.status') }}</th>
                        @if ($canEdit)
                            <th class="w-12"><span class="sr-only">{{ __('lang_v1.actions') }}</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lines as $line)
                        @php
                            $difference = (float) $line->Amount_difference;
                        @endphp
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    @if ($line->variation?->product)
                                        <x-product-thumb :product="$line->variation->product" size="sm"/>
                                    @endif
                                    <div class="min-w-0">
                                        <span class="cell-primary">{{ $line->variation?->full_name }}</span>
                                        <span class="cell-meta force-ltr">{{ $line->variation?->sub_sku }}</span>
                                    </div>
                                </div>
                            </td>

                            <td class="cell-numeric">@format_quantity($line->qty_before)</td>

                            <td class="cell-numeric">
                                @format_quantity($line->amount_after_inventory)
                                @if ($line->variation?->product?->unit)
                                    <span class="cell-meta">{{ $line->variation->product->unit->short_name }}</span>
                                @endif
                            </td>

                            <td @class([
                                'cell-numeric',
                                'text-emerald-700' => $difference > 0,
                                'text-rose-700' => $difference < 0,
                            ])>
                                @if ($difference > 0)+@endif@format_quantity($difference)
                            </td>

                            <td>
                                @if ($line->isProcessed())
                                    <span class="badge-success">{{ __('lang_v1.posted') }}</span>
                                    @if ($line->transaction)
                                        <span class="cell-meta force-ltr">{{ $line->transaction->ref_no }}</span>
                                    @endif
                                @elseif ($difference > 0)
                                    <span class="badge-info">{{ __('lang_v1.surplus') }}</span>
                                @elseif ($difference < 0)
                                    <span class="badge-danger">{{ __('lang_v1.shortage') }}</span>
                                @else
                                    <span class="badge-muted">{{ __('lang_v1.matched') }}</span>
                                @endif
                            </td>

                            @if ($canEdit)
                                <td>
                                    <div class="cell-actions">
                                        @unless ($line->isProcessed())
                                            <form method="POST"
                                                  action="{{ route('inventory.lines.destroy', [$record->id, $line->id]) }}"
                                                  data-confirm="{{ __('lang_v1.confirm_delete') }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-icon-danger"
                                                        title="{{ __('lang_v1.remove') }}"
                                                        aria-label="{{ __('lang_v1.remove') }}">
                                                    <x-nav-icon name="trash" :size="4"/>
                                                </button>
                                            </form>
                                        @endunless
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <x-table-empty :columns="$columnCount" icon="clipboard"
                                       :title="__('lang_v1.no_counted_items_yet')"
                                       :text="__('lang_v1.no_counted_items_yet_desc')"/>
                    @endforelse
                </tbody>
            </table>

            {{ $lines->links() }}
        </div>
    </x-panel>

    <div class="grid gap-6 self-start">

        @if ($documents->isNotEmpty())
            <x-panel :title="__('lang_v1.posted_documents')" icon="document">
                <dl class="dl">
                    @foreach ($documents as $document)
                        @php
                            $isWriteOff = $document->type === \App\Support\TransactionTypes::STOCK_ADJUSTMENT;
                        @endphp
                        <div class="dl-row">
                            <dt class="dl-key">
                                {{ $isWriteOff ? __('lang_v1.shortage_document') : __('lang_v1.surplus_document') }}
                                {{-- Only the write-off has a screen of its own: a
                                     found-stock document is a lot carrier, not a
                                     thing anyone edits, so its reference is shown
                                     and not linked. --}}
                                @if ($isWriteOff)
                                    <a href="{{ route('stock-adjustments.show', $document->id) }}"
                                       class="cell-link force-ltr">{{ $document->ref_no }}</a>
                                @else
                                    <span class="cell-meta force-ltr">{{ $document->ref_no }}</span>
                                @endif
                            </dt>
                            <dd class="dl-value">@format_currency($document->final_total)</dd>
                        </div>
                    @endforeach
                </dl>
            </x-panel>
        @endif

        <x-panel :title="__('lang_v1.details')" icon="clipboard">
            <x-attr-list :items="[
                'lang_v1.count_name' => $record->name,
                'lang_v1.business_location' => $record->branch->name ?? null,
                'lang_v1.created_on' => $record->created_at?->format('Y-m-d'),
                'lang_v1.count_end_date' => $record->end_date?->format('Y-m-d'),
            ]"/>
        </x-panel>

        <x-panel :title="__('lang_v1.how_this_works')" icon="info" quiet>
            <ul class="grid gap-3 text-sm text-slate-600">
                <li>{{ __('lang_v1.stock_count_note_two_directions') }}</li>
                <li>{{ __('lang_v1.stock_count_note_book_read_now') }}</li>
                <li>{{ __('lang_v1.stock_count_note_close_once') }}</li>
            </ul>
        </x-panel>
    </div>
</div>

@if ($canCount)
    @push('scripts')
    <script>
    (function () {
        const search = document.getElementById('product-search');
        const hidden = document.getElementById('variation_id');
        const submit = document.getElementById('count-submit');
        const line = document.getElementById('picked-line');
        const name = document.getElementById('picked-name');
        const sku = document.getElementById('picked-sku');
        const stock = document.getElementById('picked-stock');
        const counted = document.getElementById('counted');

        /* The count's own branch, fixed: unlike the adjustment editor there is no
           location select on this screen, because a count belongs to one shop and
           moving it mid-count is what the edit screen is for. */
        const branchId = {{ (int) $record->branch_id }};
        const stockLabel = @json(__('lang_v1.current_stock'));

        const clear = function () {
            hidden.value = '';
            submit.disabled = true;
            line.hidden = true;
        };

        const pick = function (product) {
            hidden.value = product.variation_id;
            name.textContent = product.text;
            sku.textContent = product.sku ? ' · ' + product.sku : '';
            stock.textContent = product.qty_available === null || product.qty_available === undefined
                ? ''
                : ' · ' + stockLabel + ': ' + product.qty_available;

            line.hidden = false;
            submit.disabled = false;
            counted.focus();
            counted.select();
        };

        let timer = null;
        search.addEventListener('input', function () {
            clearTimeout(timer);
            clear();

            const term = search.value.trim();
            if (term.length < 2) return;

            timer = setTimeout(async function () {
                const params = new URLSearchParams({ term: term, location_id: branchId });

                const response = await fetch('{{ route('products.list') }}?' + params, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) return;

                const results = await response.json();
                if (results.length === 0) return;

                /* One exact SKU match is a barcode scan, and a scan means "this
                   one" — anything else waits for the person to look. */
                if (results.length === 1 || results[0].sku === term) {
                    pick(results[0]);
                }
            }, 250);
        });

        /* Enter in the search box would otherwise submit the form with no
           variation chosen; here it means "take the match you found". */
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (hidden.value) counted.focus();
            }
        });
    })();
    </script>
    @endpush
@endif
@endsection
