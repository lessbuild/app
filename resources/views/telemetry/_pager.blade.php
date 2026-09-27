{{-- Previous / next links for a length-aware paginator. --}}
@if ($paginator->hasPages())
    <nav class="flex items-center justify-between gap-3 text-sm" aria-label="{{ __('Pages') }}">
        <span class="text-muted">{{ __('Page :page of :pages', ['page' => $paginator->currentPage(), 'pages' => $paginator->lastPage()]) }}</span>
        <span class="flex gap-2">
            @if ($paginator->onFirstPage())
                <x-signal.ui.button variant="quiet" size="sm" disabled>{{ __('Previous') }}</x-signal.ui.button>
            @else
                <x-signal.ui.button :href="$paginator->previousPageUrl()" variant="secondary" size="sm" rel="prev">{{ __('Previous') }}</x-signal.ui.button>
            @endif
            @if ($paginator->hasMorePages())
                <x-signal.ui.button :href="$paginator->nextPageUrl()" variant="secondary" size="sm" rel="next">{{ __('Next') }}</x-signal.ui.button>
            @else
                <x-signal.ui.button variant="quiet" size="sm" disabled>{{ __('Next') }}</x-signal.ui.button>
            @endif
        </span>
    </nav>
@endif
