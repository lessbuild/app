@if($paginator->hasPages())
    <nav aria-label="{{ __('Pagination Navigation') }}" class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-xs text-muted">
            {{ __('Showing') }} <span class="font-semibold text-ink">{{ $paginator->firstItem() ?? 0 }}</span>
            {{ __('to') }} <span class="font-semibold text-ink">{{ $paginator->lastItem() ?? 0 }}</span>
            {{ __('of') }} <span class="font-semibold text-ink">{{ $paginator->total() }}</span> {{ __('results') }}
        </p>
        <div class="flex items-center justify-between gap-2 sm:justify-end">
            @if($paginator->onFirstPage())
                <span aria-disabled="true" class="ui-btn ui-btn-secondary ui-btn-sm">{{ __('pagination.previous') }}</span>
            @else
                <x-monitor::ui.button :href="$paginator->previousPageUrl()" rel="prev" variant="secondary" size="sm">{{ __('pagination.previous') }}</x-monitor::ui.button>
            @endif
            <div class="hidden items-center gap-1 lg:flex">
                @foreach($elements as $element)
                    @if(is_string($element))
                        <span class="px-2 text-muted">{{ $element }}</span>
                    @else
                        @foreach($element as $page => $url)
                            @if($page == $paginator->currentPage())
                                <span aria-current="page" class="ui-btn ui-btn-primary ui-btn-sm">{{ $page }}</span>
                            @else
                                <x-monitor::ui.button :href="$url" aria-label="{{ __('Go to page :page', ['page' => $page]) }}" variant="secondary" size="sm">{{ $page }}</x-monitor::ui.button>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </div>
            @if($paginator->hasMorePages())
                <x-monitor::ui.button :href="$paginator->nextPageUrl()" rel="next" variant="secondary" size="sm">{{ __('pagination.next') }}</x-monitor::ui.button>
            @else
                <span aria-disabled="true" class="ui-btn ui-btn-secondary ui-btn-sm">{{ __('pagination.next') }}</span>
            @endif
        </div>
    </nav>
@endif
