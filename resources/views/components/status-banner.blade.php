{{-- Flash banner. Controllers set session('status') = ['success'=>0|1,'msg'=>...]

     Success and failure are told apart by colour AND by icon, never by colour
     alone — the palette is deliberately low-chroma, so shape carries the
     meaning for anyone reading at a glance or with a colour deficiency. --}}
@if (session('status'))
    @php
        $status = session('status');
        $ok = (bool) ($status['success'] ?? 0);
    @endphp
    <div class="{{ $ok ? 'alert-success' : 'alert-danger' }} mb-5" role="status">
        <x-nav-icon :name="$ok ? 'check-circle' : 'alert'"/>
        <span class="min-w-0 font-medium">{{ $status['msg'] ?? '' }}</span>

        {{-- Optional trailing link, for the flows that redirect away from the
             thing they just created — the POS returns to an empty terminal, so
             this is the only route back to the receipt it printed. --}}
        @if (! empty($status['link']))
            <a href="{{ $status['link'] }}" class="link ms-auto shrink-0">
                {{ $status['link_label'] ?? __('lang_v1.view') }}
            </a>
        @endif
    </div>
@endif

@if ($errors->any())
    <div class="alert-danger mb-5" role="alert">
        <x-nav-icon name="alert"/>
        <div class="min-w-0">
            <p class="font-semibold">{{ __('lang_v1.please_fix_the_following') }}</p>
            <ul class="mt-1 list-disc space-y-0.5 ps-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
