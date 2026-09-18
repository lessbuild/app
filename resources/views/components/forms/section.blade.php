@props([
    'title',
    'description',
    'collapsible' => false,
    'id' => null,
    'open' => false,
])

@if ($collapsible)
    <details @if ($id) id="{{ $id }}" @endif class="group grid gap-6 lg:grid-cols-[minmax(0,.85fr)_minmax(0,1.5fr)]" @if ($open) open @endif>
        <summary class="ui-card flex cursor-pointer list-none items-start justify-between gap-4 p-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 lg:hidden">
            <span>
                <span class="block font-bold text-primary">{{ $title }}</span>
                <span class="mt-1 block text-sm text-secondary">{{ $description }}</span>
            </span>
            <span class="shrink-0 text-xl text-secondary transition-transform group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
@else
    <div class="grid gap-6 lg:grid-cols-[minmax(0,.85fr)_minmax(0,1.5fr)]">
@endif
    <div class="hidden lg:block">
        <div class="px-4 sm:px-0">
            <h2 class="text-lg font-bold leading-tight text-primary">
                {{ $title }}
            </h2>
            <p class="text-sm text-secondary">
                {{ $description }}
            </p>
        </div>
    </div>

    <div class="ui-card overflow-hidden lg:block">
        @if (! $collapsible)
            <div class="border-b border-primary px-4 py-4 lg:hidden">
                <h2 class="font-bold text-primary">{{ $title }}</h2>
                <p class="mt-1 text-sm text-secondary">{{ $description }}</p>
            </div>
        @endif
        <div>
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="border-t border-primary">
                {{ $footer }}
            </div>
        @endisset
    </div>
@if ($collapsible)
    </details>
@else
    </div>
@endif
