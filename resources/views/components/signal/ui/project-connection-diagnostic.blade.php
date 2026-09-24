@props(['diagnostic'])

<x-signal.ui.alert :tone="$diagnostic->tone" role="status" aria-live="polite" {{ $attributes }}>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <x-signal.ui.badge :tone="$diagnostic->tone">{{ $diagnostic->status }}</x-signal.ui.badge>
                <p class="text-sm font-bold text-ink">{{ $diagnostic->summary }}</p>
            </div>
            <p class="mt-1 text-xs leading-5 text-muted">{{ $diagnostic->detail }}</p>
            @if ($diagnostic->nextStep)
                <p class="mt-1 text-xs font-semibold text-ink">{{ $diagnostic->nextStep }}</p>
            @endif
        </div>
        <div class="grid shrink-0 gap-1 text-right text-xs text-subtle">
            @if ($diagnostic->lastSucceededAt)
                <span>{{ __('Last successful update :time', ['time' => $diagnostic->lastSucceededAt->diffForHumans()]) }}</span>
                <time class="sr-only" datetime="{{ $diagnostic->lastSucceededAt->toIso8601String() }}">{{ $diagnostic->lastSucceededAt->toIso8601String() }}</time>
            @endif
            @if ($diagnostic->lastAttemptAt)
                <span>{{ __('Last attempt :time', ['time' => $diagnostic->lastAttemptAt->diffForHumans()]) }}</span>
                <time class="sr-only" datetime="{{ $diagnostic->lastAttemptAt->toIso8601String() }}">{{ $diagnostic->lastAttemptAt->toIso8601String() }}</time>
            @endif
            @if ($diagnostic->lastObservedAt)
                <span>{{ __(':label :time', ['label' => $diagnostic->lastObservedLabel ?? __('Latest activity'), 'time' => $diagnostic->lastObservedAt->diffForHumans()]) }}</span>
                <time class="sr-only" datetime="{{ $diagnostic->lastObservedAt->toIso8601String() }}">{{ $diagnostic->lastObservedAt->toIso8601String() }}</time>
            @endif
        </div>
    </div>
</x-signal.ui.alert>
