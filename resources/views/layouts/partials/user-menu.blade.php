{{-- Account menu. The one control on the header that is about *you* rather than
     the data, so it sits at the far inline end, after everything else. --}}
@php
    $user = auth()->user();
    /* Falls back through name → username: first_name can be an empty string
       rather than null, which a ?? would happily print as a blank circle. */
    $initial = mb_strtoupper(mb_substr(trim($user->user_full_name ?: $user->username), 0, 1));
@endphp

<div class="relative no-print" data-dropdown>
    <button type="button"
            class="flex items-center gap-2 rounded-lg p-1 transition hover:bg-slate-100"
            data-dropdown-trigger aria-haspopup="menu" aria-expanded="false"
            aria-label="{{ __('lang_v1.account') }}">
        <span class="avatar-md">{{ $initial }}</span>
        <span class="hidden max-w-36 truncate text-sm font-medium text-slate-700 sm:block">
            {{ $user->user_full_name }}
        </span>
        <x-nav-icon name="chevron-down" :size="4"/>
    </button>

    <div class="popover absolute end-0 mt-2 hidden w-60" data-dropdown-panel role="menu">

        {{-- Identity block: which account am I signed in as, and with what role.
             On a shared terminal that is the question this menu exists to answer. --}}
        <div class="flex items-center gap-2.5 border-b border-slate-100 px-2.5 pb-2.5 pt-1.5">
            <span class="avatar-md">{{ $initial }}</span>
            <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-slate-900">
                    {{ $user->user_full_name }}
                </span>
                <span class="block truncate text-xs text-slate-500">
                    {{ $user->role_name ?? $user->username }}
                </span>
            </span>
        </div>

        <a href="{{ route('user.profile') }}" class="nav-link mt-1.5" role="menuitem">
            <x-nav-icon name="user" :size="4"/>
            {{ __('lang_v1.profile') }}
        </a>

        {{-- Sign-out is destructive enough to earn the danger colour, but it is
             still the last item, not a primary button — nobody opens this menu
             to log out by accident. --}}
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="nav-link w-full text-start text-rose-600 hover:bg-rose-50"
                    role="menuitem">
                <x-nav-icon name="logout" :size="4"/>
                {{ __('lang_v1.logout') }}
            </button>
        </form>
    </div>
</div>
