@extends('layouts.app')
@section('title', __('lang_v1.notification_templates'))
@section('page_title', __('lang_v1.notification_templates'))

@section('content')

{{-- Not a CRUD list: the sixteen notification types are fixed, so this screen
     shows the fixed set and says which of them a tenant has actually written
     text for. "Configured" is the only real state here, and it is the thing an
     owner scans for. --}}
<x-page-head :subtitle="__('lang_v1.notification_templates_hint')"/>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>{{ __('lang_v1.notification') }}</th>
                <th>{{ __('lang_v1.email') }}</th>
                <th>{{ __('lang_v1.sms') }}</th>
                <th>{{ __('lang_v1.whatsapp') }}</th>
                @if ($canUpdate)
                    <th class="th-numeric">{{ __('lang_v1.actions') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($templates as $template)
                @php $record = $template['record']; @endphp
                <tr>
                    <td class="cell-primary">{{ $template['label'] }}</td>

                    <td>
                        @if (filled($record?->email_body))
                            <span class="{{ $record->auto_send ? 'badge-success' : 'badge-muted' }}">
                                {{ $record->auto_send ? __('lang_v1.automatic') : __('lang_v1.manual') }}
                            </span>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>

                    <td>
                        @if (filled($record?->sms_body))
                            <span class="{{ $record->auto_send_sms ? 'badge-success' : 'badge-muted' }}">
                                {{ $record->auto_send_sms ? __('lang_v1.automatic') : __('lang_v1.manual') }}
                            </span>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>

                    <td>
                        @if (filled($record?->whatsapp_text))
                            <span class="{{ $record->auto_send_wa_notif ? 'badge-success' : 'badge-muted' }}">
                                {{ $record->auto_send_wa_notif ? __('lang_v1.automatic') : __('lang_v1.manual') }}
                            </span>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>

                    @if ($canUpdate)
                        <td>
                            <div class="cell-actions">
                                <a href="{{ route('notification-templates.edit', $template['type']) }}"
                                   class="btn-icon" title="{{ __('lang_v1.edit') }}"
                                   aria-label="{{ __('lang_v1.edit') }}">
                                    <x-nav-icon name="edit" :size="4"/>
                                </a>
                            </div>
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
