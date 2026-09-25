@props(['connection', 'products'])

@php
    $source = $products[$connection['source']];
    $target = $products[$connection['target']];
    $sourceAccent = in_array($source['accent'] ?? null, ['deploy', 'monitor', 'analytics'], true) ? $source['accent'] : 'deploy';
    $targetAccent = in_array($target['accent'] ?? null, ['deploy', 'monitor', 'analytics'], true) ? $target['accent'] : 'deploy';
    $sourceIcon = in_array($source['icon'] ?? null, ['layers', 'pulse', 'chart'], true) ? $source['icon'] : 'layers';
    $targetIcon = in_array($target['icon'] ?? null, ['layers', 'pulse', 'chart'], true) ? $target['icon'] : 'layers';
@endphp

<x-signal.ui.card as="article" {{ $attributes->class(['h-full p-5 sm:p-6']) }}>
    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __(':source connects to :target', ['source' => $source['name'], 'target' => $target['name']]) }}">
        <span class="product-accent-{{ $sourceAccent }} inline-flex items-center gap-2 text-xs font-extrabold">
            <x-signal.ui.icon :name="$sourceIcon" class="size-4" />
            {{ $source['name'] }}
        </span>
        <x-signal.ui.icon name="arrow-right" class="size-4 text-muted" aria-hidden="true" />
        <span class="product-accent-{{ $targetAccent }} inline-flex items-center gap-2 text-xs font-extrabold">
            <x-signal.ui.icon :name="$targetIcon" class="size-4" />
            {{ $target['name'] }}
        </span>
        <x-signal.ui.badge tone="neutral" class="ml-auto">{{ $connection['mode'] }}</x-signal.ui.badge>
    </div>

    <h3 class="mt-5 text-lg font-extrabold text-ink">{{ $connection['title'] }}</h3>
    <p class="mt-2 text-sm leading-6 text-muted">{{ $connection['description'] }}</p>
</x-signal.ui.card>
