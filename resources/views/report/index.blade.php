@extends('layouts.app')
@section('title', __('lang_v1.reports'))
@section('page_title', __('lang_v1.reports'))

@section('content')

{{-- The hub the sidebar's single Reports entry points at.

     Tiles are filtered by permission in the controller rather than rendered and
     403'd on click, so a limited role sees a short, honest hub instead of a wall
     of doors that do not open. --}}
<x-page-head :subtitle="__('lang_v1.reports_hub_subtitle')"/>

@if (empty($reports))
    <x-panel>
        <x-empty-state icon="chart"
                       :title="__('lang_v1.no_reports_available')"
                       :text="__('lang_v1.no_reports_available_desc')"/>
    </x-panel>
@else
    <div class="rise-group grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($reports as $report)
            <a href="{{ route($report['route']) }}" class="tile gap-2 p-5">
                <span class="flex items-center gap-3">
                    <span class="stat-icon"><x-nav-icon :name="$report['icon']" :size="5"/></span>
                    <span class="card-title">{{ __($report['label']) }}</span>
                </span>
                <span class="text-sm text-slate-500">{{ __($report['desc']) }}</span>
            </a>
        @endforeach
    </div>
@endif

@endsection
