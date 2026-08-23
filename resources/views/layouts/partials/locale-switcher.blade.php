{{-- Language switcher. Arabic first: it is the primary locale.

     A globe rather than a text label: the header has no room for the word
     "Language" in two scripts, and the select's own value already names the
     language it will switch to. Submits on change — there is no Apply button to
     press, because a locale change has no other field to coordinate with. --}}
<form method="POST" action="{{ route('user.switchLanguage') }}" class="no-print">
    @csrf
    <div class="input-search-wrap">
        <span class="input-search-icon w-8"><x-nav-icon name="globe" :size="4"/></span>
        <select name="language" class="select w-auto ps-8 py-1.5 text-xs"
                onchange="this.form.submit()"
                aria-label="{{ __('lang_v1.language') }}">
            @foreach (config('constants.langs') as $code => $lang)
                <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ $lang['full_name'] }}</option>
            @endforeach
        </select>
    </div>
</form>
