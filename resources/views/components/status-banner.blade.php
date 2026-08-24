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

        {{-- Optional trailing links, for the flows that redirect away from the
             thing they just created. The POS returns to an empty terminal, so
             these are the only route back to the sale it just rang up — and the
             receipt is one of them, which is why this is a list rather than the
             single `link` it started as: after a POS sale there are two things a
             clerk wants, the paper and the record.

             Each entry is ['url' => …, 'label' => …] plus an optional
             'blank' => true. `blank` is not cosmetic: the receipt opens in its
             own tab because it auto-prints, and stealing the terminal tab to do
             that would put the next customer behind a print dialog. --}}
        @if (! empty($status['links']))
            <span class="ms-auto flex shrink-0 items-center gap-3">
                @foreach ($status['links'] as $item)
                    <a href="{{ $item['url'] }}" class="link"
                       @if ($item['blank'] ?? false) target="_blank" rel="noopener" @endif>
                        {{ $item['label'] ?? __('lang_v1.view') }}
                    </a>
                @endforeach
            </span>
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
