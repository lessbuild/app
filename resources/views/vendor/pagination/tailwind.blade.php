@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">
            {!! __('Showing') !!}
            @if ($paginator->firstItem())
                <span class="font-semibold text-ink">{{ $paginator->firstItem() }}</span>
                {!! __('to') !!}
                <span class="font-semibold text-ink">{{ $paginator->lastItem() }}</span>
            @else
                {{ $paginator->count() }}
            @endif
            {!! __('of') !!}
            <span class="font-semibold text-ink">{{ $paginator->total() }}</span>
            {!! __('results') !!}
        </p>

        <div class="flex items-center justify-between gap-2 sm:justify-end">
            @if ($paginator->onFirstPage())
                <span class="ui-btn ui-btn-secondary ui-btn-sm cursor-not-allowed opacity-60" aria-disabled="true">
                    {!! __('pagination.previous') !!}
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="ui-btn ui-btn-secondary ui-btn-sm focus-visible:ring-2 focus-visible:ring-focus" aria-label="{{ __('pagination.previous') }}">
                    {!! __('pagination.previous') !!}
                </a>
            @endif

            <div class="hidden items-center gap-1 md:flex">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="px-2 text-sm text-muted" aria-hidden="true">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page">
                                    <span class="ui-btn ui-btn-primary ui-btn-sm">{{ $page }}</span>
                                </span>
                            @else
                                <a href="{{ $url }}" class="ui-btn ui-btn-secondary ui-btn-sm focus-visible:ring-2 focus-visible:ring-focus" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                    {{ $page }}
                                </a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </div>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="ui-btn ui-btn-secondary ui-btn-sm focus-visible:ring-2 focus-visible:ring-focus" aria-label="{{ __('pagination.next') }}">
                    {!! __('pagination.next') !!}
                </a>
            @else
                <span class="ui-btn ui-btn-secondary ui-btn-sm cursor-not-allowed opacity-60" aria-disabled="true">
                    {!! __('pagination.next') !!}
                </span>
            @endif
        </div>
    </nav>
@endif
