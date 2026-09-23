@props(['priority'])

<x-signal.ui.card class="p-4">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="ui-eyebrow truncate">{{ $priority->projectName }}</p>
            <h3 class="mt-2 text-base font-extrabold text-ink">{{ $priority->title }}</h3>
        </div>
        <x-signal.ui.badge :tone="$priority->tone">{{ $priority->badge }}</x-signal.ui.badge>
    </div>
    <p class="mt-3 text-sm leading-6 text-muted">{{ $priority->detail }}</p>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <x-signal.ui.link :href="$priority->actionUrl" size="sm">
            {{ $priority->actionLabel }}
            <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#arrow-right"></use></svg>
        </x-signal.ui.link>
        @if ($priority->updatedAt)
            <time class="text-xs text-subtle" datetime="{{ $priority->updatedAt->toIso8601String() }}">
                {{ __('Updated :time', ['time' => $priority->updatedAt->diffForHumans()]) }}
            </time>
        @endif
    </div>
</x-signal.ui.card>
