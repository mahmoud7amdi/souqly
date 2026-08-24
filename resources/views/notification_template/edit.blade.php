@extends('layouts.app')
@section('title', __('lang_v1.notification_templates'))
@section('page_title', __('lang_v1.notification_templates'))

@section('content')

<x-page-head :title="$label"
             :back="route('notification-templates.index')"
             :backLabel="__('lang_v1.notification_templates')"/>

<form method="POST" action="{{ route('notification-templates.update', $templateFor) }}" class="max-w-3xl">
    @csrf
    @method('PUT')

    <div class="grid gap-6">
        {{-- The tag reference sits above the fields, not below them: a mistyped
             placeholder is not rejected by anything — it is printed literally to
             the customer — so the vocabulary has to be visible while typing. --}}
        <x-panel :title="__('lang_v1.available_tags')" icon="tag" quiet>
            <p class="hint">{{ __('lang_v1.available_tags_hint') }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($tags as $tag)
                    <code class="badge-muted force-ltr">{{ $tag }}</code>
                @endforeach
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.email')" icon="mail">
            <div class="form-grid">
                <div class="field sm:col-span-2">
                    <label for="subject" class="label">{{ __('lang_v1.subject') }}</label>
                    <input id="subject" name="subject" class="input"
                           value="{{ old('subject', $record->subject ?? '') }}">
                </div>

                <div class="field sm:col-span-2">
                    <label for="email_body" class="label">{{ __('lang_v1.email_body') }}</label>
                    <textarea id="email_body" name="email_body" rows="6" class="textarea"
                    >{{ old('email_body', $record->email_body ?? '') }}</textarea>
                    @error('email_body')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="cc" class="label">{{ __('lang_v1.cc') }}</label>
                    <input id="cc" name="cc" class="input force-ltr" value="{{ old('cc', $record->cc ?? '') }}">
                    <p class="hint">{{ __('lang_v1.comma_separated') }}</p>
                </div>

                <div class="field">
                    <label for="bcc" class="label">{{ __('lang_v1.bcc') }}</label>
                    <input id="bcc" name="bcc" class="input force-ltr" value="{{ old('bcc', $record->bcc ?? '') }}">
                </div>

                <div class="field sm:col-span-2">
                    <label class="checkbox-row">
                        <input type="checkbox" name="auto_send" value="1" class="checkbox"
                               @checked(old('auto_send', $record->auto_send ?? false))>
                        <span class="checkbox-label">
                            {{ __('lang_v1.auto_send_email') }}
                            <span class="checkbox-hint">{{ __('lang_v1.auto_send_hint') }}</span>
                        </span>
                    </label>
                </div>
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.sms')" icon="phone">
            <div class="form-grid">
                <div class="field sm:col-span-2">
                    <label for="sms_body" class="label">{{ __('lang_v1.sms_body') }}</label>
                    <textarea id="sms_body" name="sms_body" rows="3" class="textarea"
                    >{{ old('sms_body', $record->sms_body ?? '') }}</textarea>
                    @error('sms_body')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field sm:col-span-2">
                    <label class="checkbox-row">
                        <input type="checkbox" name="auto_send_sms" value="1" class="checkbox"
                               @checked(old('auto_send_sms', $record->auto_send_sms ?? false))>
                        <span class="checkbox-label">{{ __('lang_v1.auto_send_sms') }}</span>
                    </label>
                </div>
            </div>
        </x-panel>

        <x-panel :title="__('lang_v1.whatsapp')" icon="globe">
            <div class="form-grid">
                <div class="field sm:col-span-2">
                    <label for="whatsapp_text" class="label">{{ __('lang_v1.whatsapp_text') }}</label>
                    <textarea id="whatsapp_text" name="whatsapp_text" rows="3" class="textarea"
                    >{{ old('whatsapp_text', $record->whatsapp_text ?? '') }}</textarea>
                    @error('whatsapp_text')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field sm:col-span-2">
                    <label class="checkbox-row">
                        <input type="checkbox" name="auto_send_wa_notif" value="1" class="checkbox"
                               @checked(old('auto_send_wa_notif', $record->auto_send_wa_notif ?? false))>
                        <span class="checkbox-label">{{ __('lang_v1.auto_send_whatsapp') }}</span>
                    </label>
                </div>
            </div>
        </x-panel>
    </div>

    <div class="form-actions">
        <a href="{{ route('notification-templates.index') }}" class="btn-secondary">{{ __('lang_v1.cancel') }}</a>
        <button type="submit" class="btn-primary">
            <x-nav-icon name="save"/>
            {{ __('lang_v1.update') }}
        </button>
    </div>
</form>
@endsection
