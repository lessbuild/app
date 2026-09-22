@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" class="flex flex-wrap items-center justify-between gap-3">
        {{-- Previous Page Link --}}
        @if ($paginator->onFirstPage())
            <span class="ui-btn ui-btn-secondary ui-btn-sm cursor-default opacity-70" aria-disabled="true">
                {!! __('pagination.previous') !!}
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="ui-btn ui-btn-secondary ui-btn-sm focus-visible:ring-2 focus-visible:ring-focus">
                {!! __('pagination.previous') !!}
            </a>
        @endif

        {{-- Next Page Link --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="ui-btn ui-btn-secondary ui-btn-sm focus-visible:ring-2 focus-visible:ring-focus">
                {!! __('pagination.next') !!}
            </a>
        @else
            <span class="ui-btn ui-btn-secondary ui-btn-sm cursor-default opacity-70" aria-disabled="true">
                {!! __('pagination.next') !!}
            </span>
        @endif
    </nav>
@endif
