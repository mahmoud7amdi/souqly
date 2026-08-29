@extends('layouts.app')
@section('title', $record->name)
@section('page_title', __('assetmanagement.assets').' — '.$record->name)

@section('content')

@php
    $allocated = $record->allocated_quantity;
    $available = $record->available_quantity;

    $canSeeMaintenance = auth()->user()->can('asset.view_own_maintenance')
        || auth()->user()->can('asset.view_all_maintenance');

    $allocationColumns = 5 + (int) $canAllocate;
@endphp

<x-page-head :title="$record->name" :back="route('assets.index')"
             :backLabel="__('assetmanagement.assets')">
    <x-slot:subtitle>
        <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="force-ltr">{{ $record->asset_code }}</span>
            <span class="text-slate-300">&middot;</span>
            <span>{{ or_dash($record->businessLocation->name ?? null) }}</span>
            <span class="ms-1 inline-flex flex-wrap items-center gap-1.5">
                @if (! $record->is_allocatable)
                    <span class="badge-muted">{{ __('assetmanagement.not_allocatable') }}</span>
                @elseif ($allocated > 0 && $available <= 0)
                    <span class="badge-brand">{{ __('assetmanagement.fully_allocated') }}</span>
                @elseif ($allocated > 0)
                    <span class="badge-warning">{{ __('assetmanagement.partly_allocated') }}</span>
                @else
                    <span class="badge-success">{{ __('assetmanagement.available') }}</span>
                @endif

                @if ($record->is_in_warranty)
                    <span class="badge-info">{{ __('assetmanagement.in_warranty') }}</span>
                @endif
            </span>
        </span>
    </x-slot:subtitle>

    @if ($canEdit)
        <a href="{{ route('assets.edit', $record->id) }}" class="btn-secondary">
            <x-nav-icon name="edit"/>
            {{ __('lang_v1.edit') }}
        </a>
    @endif

    {{-- Hidden rather than shown-and-refused while anything is signed out, which is
         the same rule the list screen follows and the same rule the service
         enforces. --}}
    @if ($canDelete && $allocated <= 0)
        <form method="POST" action="{{ route('assets.destroy', $record->id) }}"
              data-confirm="{{ __('lang_v1.confirm_delete') }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-danger">
                <x-nav-icon name="trash"/>
                {{ __('lang_v1.delete') }}
            </button>
        </form>
    @endif
</x-page-head>

{{-- Owned, out, and here: three numbers that must add up, shown together so they
     can be checked against each other at a glance. Book value sits fourth because
     it is the one figure nobody counts on a shelf. --}}
<div class="section">
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('assetmanagement.owned_quantity')"
                :value="format_quantity($record->quantity)"
                icon="box"/>

        <x-stat :label="__('assetmanagement.allocated_out')"
                :value="format_quantity($allocated)"
                icon="user-plus"/>

        <x-stat :label="__('assetmanagement.available_quantity')"
                :value="format_quantity($available)"
                icon="check-circle"
                :tone="$available <= 0 && $record->is_allocatable ? 'warning' : null"/>

        <x-stat :label="__('assetmanagement.current_value')"
                :value="format_currency($record->current_value)"
                icon="receipt"
                :hint="__('assetmanagement.acquisition_was', ['amount' => format_currency($record->acquisition_cost)])"/>
    </div>
</div>

@if ($canAllocate)
    <x-panel :title="__('assetmanagement.allocate_asset')" icon="user-plus"
             :subtitle="__('assetmanagement.allocate_hint')" class="mb-6">
        <form method="POST" action="{{ route('assets.allocate', $record->id) }}">
            @csrf

            <div class="form-grid-3">
                <div class="field">
                    <label for="receiver" class="label label-required">{{ __('assetmanagement.receiver') }}</label>
                    <select id="receiver" name="receiver"
                            @class(['select', 'input-invalid' => $errors->has('receiver')]) required>
                        <option value="">{{ __('lang_v1.please_select') }}</option>
                        @foreach ($users as $id => $name)
                            <option value="{{ $id }}" @selected(old('receiver') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('receiver')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="quantity" class="label label-required">{{ __('lang_v1.quantity') }}</label>
                    <input type="number" step="0.0001" min="0.0001" max="{{ $available }}"
                           id="quantity" name="quantity"
                           @class(['input', 'input-numeric', 'input-invalid' => $errors->has('quantity')])
                           value="{{ old('quantity', 1) }}" required>
                    <p class="hint">{{ __('assetmanagement.available_is', ['qty' => format_quantity($available)]) }}</p>
                    @error('quantity')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                {{-- A return date rather than a duration, because "due back on the
                     14th" is what gets written on the sign-out sheet — and it is
                     what the overdue badge below reads. --}}
                <div class="field">
                    <label for="allocated_upto" class="label">{{ __('assetmanagement.due_back') }}</label>
                    <input type="date" id="allocated_upto" name="allocated_upto"
                           @class(['input', 'input-invalid' => $errors->has('allocated_upto')])
                           value="{{ old('allocated_upto') }}">
                    <p class="hint">{{ __('assetmanagement.due_back_hint') }}</p>
                    @error('allocated_upto')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field sm:col-span-2 lg:col-span-2">
                    <label for="reason" class="label">{{ __('assetmanagement.reason') }}</label>
                    <input id="reason" name="reason"
                           @class(['input', 'input-invalid' => $errors->has('reason')])
                           value="{{ old('reason') }}" maxlength="1000"
                           placeholder="{{ __('assetmanagement.reason_placeholder') }}">
                    @error('reason')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="transaction_datetime" class="label">{{ __('assetmanagement.handed_over_on') }}</label>
                    <input type="date" id="transaction_datetime" name="transaction_datetime"
                           @class(['input', 'input-invalid' => $errors->has('transaction_datetime')])
                           value="{{ old('transaction_datetime') }}">
                    <p class="hint">{{ __('assetmanagement.defaults_to_today') }}</p>
                    @error('transaction_datetime')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="mt-4 flex justify-end">
                <button type="submit" class="btn-primary">
                    <x-nav-icon name="user-plus"/>
                    {{ __('assetmanagement.allocate') }}
                </button>
            </div>
        </form>
    </x-panel>
@elseif ($record->is_allocatable && $canEdit)
    <div class="alert-info mb-6" role="note">
        <x-nav-icon name="info"/>
        <div class="min-w-0">
            <p class="font-semibold">{{ __('assetmanagement.nothing_available') }}</p>
            <p class="mt-0.5">{{ __('assetmanagement.nothing_available_desc') }}</p>
        </div>
    </div>
@endif

<div class="grid gap-6 lg:grid-cols-4">

    {{-- Allocations, each with its returns folded into it. A revocation is not a
         row of its own here: it closes the allocation above it, and listing both
         flat would show one movement twice and make the outstanding column
         meaningless. --}}
    <x-panel :title="__('assetmanagement.allocation_history')" icon="transfer"
             :count="$allocations->total()" class="lg:col-span-3" flush>
        <div class="table-wrap table-flush">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('lang_v1.reference_no') }}</th>
                        <th>{{ __('assetmanagement.receiver') }}</th>
                        <th class="th-numeric">{{ __('lang_v1.quantity') }}</th>
                        <th class="th-numeric">{{ __('assetmanagement.outstanding') }}</th>
                        <th>{{ __('lang_v1.status') }}</th>
                        @if ($canAllocate)
                            <th class="th-numeric">{{ __('assetmanagement.return_asset') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($allocations as $allocation)
                        @php
                            $outstanding = $allocation->quantity_outstanding;
                            $isOverdue = $outstanding > 0
                                && $allocation->allocated_upto
                                && $allocation->allocated_upto->isPast();
                        @endphp
                        <tr>
                            <td class="whitespace-nowrap">
                                <span class="cell-primary force-ltr">{{ $allocation->ref_no }}</span>
                                <span class="cell-meta force-ltr">@format_date($allocation->transaction_datetime)</span>
                            </td>

                            <td>
                                {{ or_dash($allocation->receiverUser->user_full_name ?? null) }}
                                @if ($allocation->reason)
                                    <span class="cell-meta">{{ $allocation->reason }}</span>
                                @endif
                            </td>

                            <td class="cell-numeric">@format_quantity($allocation->quantity)</td>

                            <td class="cell-numeric">
                                @format_quantity($outstanding)
                                @if ($allocation->allocated_upto)
                                    <span class="cell-meta force-ltr">
                                        {{ __('assetmanagement.due') }} @format_date($allocation->allocated_upto)
                                    </span>
                                @endif
                            </td>

                            <td>
                                @if ($outstanding <= 0)
                                    <span class="badge-success">{{ __('assetmanagement.returned') }}</span>
                                @elseif ($isOverdue)
                                    <span class="badge-danger">{{ __('assetmanagement.overdue') }}</span>
                                @elseif ($outstanding < $allocation->quantity)
                                    <span class="badge-warning">{{ __('assetmanagement.partly_returned') }}</span>
                                @else
                                    <span class="badge-info">{{ __('assetmanagement.out') }}</span>
                                @endif
                            </td>

                            @if ($canAllocate)
                                <td>
                                    @if ($outstanding > 0)
                                        {{-- One control for both cases: leave the box
                                             empty and the whole outstanding quantity
                                             comes back, which is what happens nine
                                             times in ten; type a number and three of
                                             the five tablets come back. --}}
                                        <form method="POST"
                                              action="{{ route('assets.revoke', [$record->id, $allocation->id]) }}"
                                              class="flex items-center justify-end gap-2">
                                            @csrf
                                            <input type="number" step="0.0001" min="0.0001"
                                                   max="{{ $outstanding }}" name="quantity"
                                                   class="input-numeric w-24"
                                                   placeholder="{{ __('assetmanagement.all') }}"
                                                   aria-label="{{ __('assetmanagement.quantity_to_return') }}">
                                            <button type="submit" class="btn-secondary btn-sm">
                                                <x-nav-icon name="undo" :size="4"/>
                                                {{ __('assetmanagement.return_asset') }}
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <x-table-empty :columns="$allocationColumns" icon="transfer"
                                       :title="__('assetmanagement.never_allocated')"
                                       :text="__('assetmanagement.never_allocated_desc')"/>
                    @endforelse
                </tbody>
            </table>

            {{ $allocations->links() }}
        </div>
    </x-panel>

    <div class="grid gap-6 self-start">

        {{-- ============ Warranty ============ --}}
        <x-panel :title="__('assetmanagement.warranty')" icon="shield" :count="$warranties->count()">
            @forelse ($warranties as $warranty)
                @php
                    $isCurrent = $warranty->start_date <= now()->toDateString()
                        && $warranty->end_date >= now()->toDateString();
                @endphp
                {{-- `.divider` carries my-7, which is right between sections of a
                     page and far too much between two lines in a side panel. --}}
                <div @class(['mt-4 border-t border-slate-200 pt-4' => ! $loop->first])>
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="cell-primary force-ltr">
                                @format_date($warranty->start_date) — @format_date($warranty->end_date)
                            </p>
                            <p class="cell-meta">
                                @if ($isCurrent)
                                    <span class="badge-success">{{ __('assetmanagement.in_warranty') }}</span>
                                @else
                                    <span class="badge-muted">{{ __('assetmanagement.expired') }}</span>
                                @endif
                                @if ((float) $warranty->additional_cost > 0)
                                    <span class="ms-1">{{ format_currency($warranty->additional_cost) }}</span>
                                @endif
                            </p>
                            @if ($warranty->additional_note)
                                <p class="cell-meta">{{ $warranty->additional_note }}</p>
                            @endif
                        </div>

                        @if ($canEdit)
                            <form method="POST"
                                  action="{{ route('assets.warranties.destroy', [$record->id, $warranty->id]) }}"
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
                </div>
            @empty
                <x-empty-state icon="shield" :title="__('assetmanagement.no_warranty')"
                               :text="__('assetmanagement.no_warranty_desc')" compact/>
            @endforelse

            @if ($canEdit)
                <x-slot:footer>
                    <form method="POST" action="{{ route('assets.warranties.store', $record->id) }}"
                          class="grid w-full gap-3">
                        @csrf
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="field">
                                <label for="w_start" class="label label-required">{{ __('assetmanagement.warranty_from') }}</label>
                                <input type="date" id="w_start" name="start_date"
                                       @class(['input', 'input-invalid' => $errors->has('start_date')])
                                       value="{{ old('start_date') }}" required>
                                @error('start_date')<p class="field-error">{{ $message }}</p>@enderror
                            </div>
                            <div class="field">
                                <label for="w_end" class="label label-required">{{ __('assetmanagement.warranty_to') }}</label>
                                <input type="date" id="w_end" name="end_date"
                                       @class(['input', 'input-invalid' => $errors->has('end_date')])
                                       value="{{ old('end_date') }}" required>
                                @error('end_date')<p class="field-error">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div class="field">
                            <label for="w_cost" class="label">{{ __('assetmanagement.warranty_cost') }}</label>
                            <input type="number" step="0.0001" min="0" id="w_cost" name="additional_cost"
                                   @class(['input-numeric', 'input-invalid' => $errors->has('additional_cost')])
                                   value="{{ old('additional_cost') }}">
                            <p class="hint">{{ __('assetmanagement.warranty_cost_hint') }}</p>
                            @error('additional_cost')<p class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field">
                            <label for="w_note" class="label">{{ __('lang_v1.note') }}</label>
                            <input id="w_note" name="additional_note"
                                   @class(['input', 'input-invalid' => $errors->has('additional_note')])
                                   value="{{ old('additional_note') }}" maxlength="1000">
                            @error('additional_note')<p class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <button type="submit" class="btn-secondary btn-sm justify-self-end">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('assetmanagement.add_warranty') }}
                        </button>
                    </form>
                </x-slot:footer>
            @endif
        </x-panel>

        {{-- ============ Maintenance ============
             The last ten only, and no editing here: the module has a screen of its
             own where a technician reads their whole queue, and duplicating it into
             a side panel would mean two places to change one workflow. --}}
        @if ($canSeeMaintenance)
            <x-panel :title="__('assetmanagement.maintenance')" icon="cog" :count="$maintenances->count()">
                <x-slot:actions>
                    @if ($canEdit)
                        <a href="{{ route('asset-maintenance.create', ['asset_id' => $record->id]) }}"
                           class="btn-secondary btn-sm">
                            <x-nav-icon name="plus" :size="4"/>
                            {{ __('assetmanagement.raise_job') }}
                        </a>
                    @endif
                </x-slot:actions>

                @forelse ($maintenances as $job)
                    <div @class(['mt-4 border-t border-slate-200 pt-4' => ! $loop->first])>
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="cell-primary force-ltr">{{ or_dash($job->maitenance_id) }}</p>
                                <p class="cell-meta">
                                    {{ \App\Modules\AssetManagement\Models\AssetMaintenance::statuses()[$job->status] ?? or_dash($job->status) }}
                                    @if ($job->assignedTo)
                                        &middot; {{ $job->assignedTo->user_full_name }}
                                    @endif
                                </p>
                                @if ($job->details)
                                    <p class="cell-meta">{{ \Illuminate\Support\Str::limit($job->details, 80) }}</p>
                                @endif
                            </div>

                            @if (in_array($job->status, ['completed', 'cancelled'], true))
                                <span class="badge-muted">{{ __('assetmanagement.closed') }}</span>
                            @else
                                <span class="badge-warning">{{ __('assetmanagement.open') }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-empty-state icon="cog" :title="__('assetmanagement.no_maintenance')"
                                   :text="__('assetmanagement.no_maintenance_desc')" compact/>
                @endforelse

                <x-slot:footer>
                    <a href="{{ route('asset-maintenance.index', ['asset_id' => $record->id]) }}"
                       class="btn-ghost btn-sm">
                        {{ __('assetmanagement.all_maintenance_jobs') }}
                        <x-nav-icon name="chevron-forward" :size="4"/>
                    </a>
                </x-slot:footer>
            </x-panel>
        @endif

        {{-- ============ The paperwork ============ --}}
        <x-panel :title="__('lang_v1.details')" icon="clipboard">
            <x-attr-list :items="[
                'assetmanagement.asset_code' => $record->asset_code,
                'assetmanagement.model' => $record->model,
                'assetmanagement.serial_no' => $record->serial_no,
                'lang_v1.business_location' => $record->businessLocation->name ?? null,
                'assetmanagement.purchase_date' => $record->purchase_date?->format('Y-m-d'),
                'assetmanagement.purchase_type' => \App\Modules\AssetManagement\Models\Asset::purchaseTypes()[$record->purchase_type] ?? null,
                'assetmanagement.unit_price' => format_currency($record->unit_price),
                'assetmanagement.depreciation_rate' => $record->depreciation ? format_number($record->depreciation).'%' : null,
                'lang_v1.added_by' => $record->createdBy->user_full_name ?? null,
            ]"/>

            @if ($record->description)
                <p class="mt-5 border-t border-slate-200 pt-4 text-sm text-slate-600">{{ $record->description }}</p>
            @endif
        </x-panel>
    </div>
</div>
@endsection
