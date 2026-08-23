{{--
    Pagination.

    Published over Laravel's bundled Tailwind paginator, which could not be used
    as shipped for three reasons:

      1. It styles itself with Tailwind's default `gray` ramp. This app overrides
         `slate`, not `gray`, so the stock paginator rendered off-palette against
         every table it sat under.
      2. It spaces its cells with `ml-px` — a physical margin, which collapses to
         the wrong side under RTL.
      3. Its prev/next chevrons are hard-coded left/right and do not mirror, so
         in Arabic "next" pointed backwards.

    Rewritten on the .page-link primitives: logical spacing only, and the arrows
    are .icon-directional so they mirror with the document.

    The summary reads "1–25 of 240" rather than a sentence, so it needs no
    grammatical agreement in either language and stays honest when a filter
    narrows the set.
--}}
@if ($paginator->hasPages())
    <nav class="pagination no-print" role="navigation"
         aria-label="{{ __('lang_v1.pagination_navigation') }}">

        <p class="pagination-summary force-ltr">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
            <span class="text-slate-400">/</span>
            {{ $paginator->total() }}
        </p>

        <ul class="pagination-list">

            {{-- Previous --}}
            <li>
                @if ($paginator->onFirstPage())
                    <span class="page-link-disabled" aria-disabled="true"
                          aria-label="{{ __('lang_v1.previous') }}">
                        <x-nav-icon name="chevron-back" :size="4"/>
                    </span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" class="page-link"
                       rel="prev" aria-label="{{ __('lang_v1.previous') }}">
                        <x-nav-icon name="chevron-back" :size="4"/>
                    </a>
                @endif
            </li>

            {{-- Numbered pages. Hidden on the narrowest screens, where prev/next
                 and the summary are enough and 11 cells would wrap twice. --}}
            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="hidden sm:list-item">
                        <span class="pagination-gap" aria-hidden="true">{{ $element }}</span>
                    </li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li class="hidden sm:list-item">
                            @if ($page == $paginator->currentPage())
                                <span class="page-link-active force-ltr" aria-current="page">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="page-link force-ltr"
                                   aria-label="{{ __('lang_v1.go_to_page', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            {{-- Next --}}
            <li>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" class="page-link"
                       rel="next" aria-label="{{ __('lang_v1.next') }}">
                        <x-nav-icon name="chevron-forward" :size="4"/>
                    </a>
                @else
                    <span class="page-link-disabled" aria-disabled="true"
                          aria-label="{{ __('lang_v1.next') }}">
                        <x-nav-icon name="chevron-forward" :size="4"/>
                    </span>
                @endif
            </li>
        </ul>
    </nav>
@endif
