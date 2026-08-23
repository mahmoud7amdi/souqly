{{-- Notification bell. Count is refreshed over Pusher when configured, else polled.

     The badge is positioned on the button, not inside the label, so a two-digit
     count grows outward instead of nudging the icon off centre. --}}
<a href="{{ route('notifications.index') }}" class="btn-icon relative"
   aria-label="{{ __('lang_v1.notifications') }}">
    <x-nav-icon name="bell"/>

    {{-- Hidden until app.js writes a non-zero count into it. aria-live so a
         screen reader hears a new notification arrive without a page load. --}}
    <span id="notification-count"
          class="absolute -top-0.5 -end-0.5 hidden min-w-4 rounded-full bg-rose-600 px-1
                 text-center text-[0.625rem] font-bold leading-4 text-white"
          aria-live="polite"></span>
</a>
