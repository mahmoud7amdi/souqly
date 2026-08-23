@extends('layouts.app')
@section('title', __('lang_v1.notifications'))
@section('page_title', __('lang_v1.notifications'))

@section('content')

<x-page-head :subtitle="trans_choice('lang_v1.record_count', $notifications->total(), ['count' => $notifications->total()])">
    @if ($notifications->total() > 0)
        <form method="POST" action="{{ route('notifications.markAllRead') }}">
            @csrf
            <button type="submit" class="btn-secondary">
                <x-nav-icon name="check"/>
                {{ __('lang_v1.mark_all_read') }}
            </button>
        </form>
    @endif
</x-page-head>

<div class="card">
    <ul class="divide-y divide-slate-100">
        @forelse ($notifications as $notification)
            {{-- Unread carries two cues, not one: a filled dot and a tinted row.
                 The dot alone is 8px of colour, which is the whole signal for
                 someone who cannot pick brand-500 out of slate-200. --}}
            <li @class([
                'flex items-start gap-3 px-5 py-4 transition',
                'bg-brand-50/40' => ! $notification->read_at,
                'hover:bg-slate-50' => (bool) $notification->read_at,
            ])>
                <span @class([
                    'mt-1.5 size-2 shrink-0 rounded-full',
                    'bg-brand-500' => ! $notification->read_at,
                    'bg-slate-200' => (bool) $notification->read_at,
                ])>
                    <span class="sr-only">
                        {{ $notification->read_at ? __('lang_v1.read') : __('lang_v1.unread') }}
                    </span>
                </span>

                <div class="min-w-0 flex-1">
                    {{-- The whole title is the link; opening a notification is also
                         what marks it read, so there is no second control. --}}
                    <a href="{{ route('notifications.show', $notification->id) }}"
                       class="block text-sm font-semibold text-slate-900 hover:text-brand-700">
                        {{ $notification->data['title'] ?? class_basename($notification->type) }}
                    </a>

                    @if (! empty($notification->data['body']))
                        <p class="mt-0.5 text-sm text-slate-600">{{ $notification->data['body'] }}</p>
                    @endif

                    <p class="mt-1 text-xs text-slate-400">
                        @format_datetime($notification->created_at)
                    </p>
                </div>
            </li>
        @empty
            <li>
                <x-empty-state icon="bell" :title="__('lang_v1.nothing_here_yet')"
                               :text="__('lang_v1.no_notifications_hint')"/>
            </li>
        @endforelse
    </ul>

    {{-- .pagination draws its own top rule and padding, so it needs no wrapper. --}}
    {{ $notifications->links() }}
</div>
@endsection
