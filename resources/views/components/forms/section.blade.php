@props([
    'title',
    'description',
    'collapsible' => false,
    'id' => null,
    'open' => false,
])

@if ($collapsible)
    <details @if ($id) id="{{ $id }}" @endif class="ui-responsive-details group grid gap-6 lg:grid-cols-[minmax(0,.85fr)_minmax(0,1.5fr)]" open data-responsive-details data-responsive-details-mobile-open="{{ $open ? 'true' : 'false' }}">
        <summary class="ui-card flex cursor-pointer list-none items-start justify-between gap-4 p-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary lg:hidden">
            <span>
                <span class="block font-bold text-ink">{{ $title }}</span>
                <span class="mt-1 block text-sm text-muted">{{ $description }}</span>
            </span>
            <span class="shrink-0 text-xl text-muted transition-transform group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
@else
    <div class="grid gap-6 lg:grid-cols-[minmax(0,.85fr)_minmax(0,1.5fr)]">
@endif
    <div class="hidden lg:block">
        <div class="px-4 sm:px-0">
            <h2 class="text-lg font-bold leading-tight text-ink">
                {{ $title }}
            </h2>
            <p class="text-sm text-muted">
                {{ $description }}
            </p>
        </div>
    </div>

    <div class="ui-responsive-details__content ui-card overflow-hidden lg:block">
        @if (! $collapsible)
            <div class="border-b border-line px-4 py-4 lg:hidden">
                <h2 class="font-bold text-ink">{{ $title }}</h2>
                <p class="mt-1 text-sm text-muted">{{ $description }}</p>
            </div>
        @endif
        <div>
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="border-t border-line">
                {{ $footer }}
            </div>
        @endisset
    </div>
@if ($collapsible)
    </details>
@else
    </div>
@endif
