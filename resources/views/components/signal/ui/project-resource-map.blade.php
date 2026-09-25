@props([
    'products' => [],
    'resources',
    'connections',
    'resourceDestinations' => [],
    'hiddenConnectionCount' => 0,
    'infrastructure' => [],
])

@php
    $resourcesByProduct = $resources->groupBy('product');
    $visibleConnections = $connections->filter(function ($connection) use ($resourceDestinations): bool {
        foreach (['source', 'target'] as $endpoint) {
            $resource = $connection->{$endpoint.'Resource'};
            $resourceId = (string) ($resource?->getKey() ?? $connection->{$endpoint.'_resource_id'} ?? '');

            if (($resourceDestinations[$resourceId]?->state ?? null) !== \App\Core\Data\Projects\ProjectResourceDestinationState::Available) {
                return false;
            }
        }

        return true;
    })->values();
    $hiddenConnectionCount += $connections->count() - $visibleConnections->count();
@endphp

<div {{ $attributes->class(['grid gap-4']) }}>
    <div>
        <p class="ui-eyebrow">{{ __('Cross-app view') }}</p>
        <h3 class="mt-1 text-base font-extrabold text-ink">{{ __('Resource map') }}</h3>
        <p class="mt-1 text-sm leading-6 text-muted">{{ __('See which app resources belong to this project and how their workflows are connected.') }}</p>
    </div>

    <x-signal.ui.card class="overflow-hidden">
        <div class="grid gap-3 p-4 md:grid-cols-3 sm:p-5">
            @foreach ($products as $productKey => $productLabel)
                @php
                    $productResources = $resourcesByProduct->get($productKey, collect());
                @endphp
                <section aria-labelledby="project-resource-map-{{ $productKey }}" class="min-w-0 rounded-panel border border-line bg-surface-muted p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 id="project-resource-map-{{ $productKey }}" class="text-sm font-extrabold text-ink">{{ $productLabel }}</h4>
                        <x-signal.ui.badge :tone="$productResources->isNotEmpty() ? 'success' : 'neutral'">
                            {{ trans_choice(':count resource|:count resources', $productResources->count(), ['count' => $productResources->count()]) }}
                        </x-signal.ui.badge>
                    </div>

                    @if ($productResources->isEmpty())
                        <p class="mt-3 text-xs leading-5 text-muted">{{ __('No resources linked yet.') }}</p>
                    @else
                        <ul class="mt-3 grid gap-2">
                            @foreach ($productResources as $resource)
                                @php
                                    $resourceDestination = $resourceDestinations[(string) $resource->getKey()] ?? null;
                                    $destinationState = $resourceDestination?->state
                                        ?? \App\Core\Data\Projects\ProjectResourceDestinationState::Unavailable;
                                    $detailsAvailable = $destinationState === \App\Core\Data\Projects\ProjectResourceDestinationState::Available;
                                @endphp
                                <li class="min-w-0 rounded-control border border-line bg-surface px-3 py-2.5">
                                    <div class="flex min-w-0 items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            @if (! $detailsAvailable)
                                                <p class="text-sm font-bold text-ink">{{ __('Resource details hidden') }}</p>
                                                <p class="mt-1 text-xs leading-5 text-muted">{{ $destinationState->description() }}</p>
                                            @else
                                                <p class="truncate text-sm font-bold text-ink">
                                                @if ($resourceDestination?->url)
                                                    <a
                                                        href="{{ $resourceDestination->url }}"
                                                        aria-label="{{ __('Open :resource in :product', ['resource' => $resource->name ?: str($resource->resource_type)->headline(), 'product' => $productLabel]) }}"
                                                        class="text-primary underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                                    >{{ $resource->name ?: str($resource->resource_type)->headline() }}</a>
                                                @else
                                                    {{ $resource->name ?: str($resource->resource_type)->headline() }}
                                                @endif
                                            </p>
                                            <p class="mt-1 truncate text-xs text-muted">
                                                {{ str($resource->resource_type)->headline() }}
                                                @if ($resource->environment?->name)
                                                    · {{ $resource->environment->name }}
                                                @endif
                                            </p>
                                            @endif
                                        </div>
                                        <div class="flex shrink-0 flex-wrap justify-end gap-1.5">
                                            <x-signal.ui.badge :tone="$destinationState->tone()">{{ $destinationState->label() }}</x-signal.ui.badge>
                                            @if ($resource->status !== 'active')
                                                <x-signal.ui.badge tone="neutral">{{ str($resource->status)->headline() }}</x-signal.ui.badge>
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>

        <div class="border-t border-line px-4 py-4 sm:px-5">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h4 class="text-sm font-extrabold text-ink">{{ __('Connected workflows') }}</h4>
                    <p class="mt-1 text-xs leading-5 text-muted">{{ __('Only configured data flows are shown. Product data and subscriptions remain separate.') }}</p>
                </div>
                <span class="text-xs text-muted">{{ trans_choice(':count workflow|:count workflows', $visibleConnections->count(), ['count' => $visibleConnections->count()]) }}</span>
            </div>

            @if ($hiddenConnectionCount > 0)
                <x-signal.ui.alert tone="warning" class="mt-3 text-xs leading-5">
                    {{ __('Some configured workflows are temporarily hidden until access to both resources can be confirmed.') }}
                </x-signal.ui.alert>
            @endif

            @if ($visibleConnections->isEmpty())
                <p class="mt-4 rounded-control border border-dashed border-line px-4 py-3 text-sm leading-6 text-muted">
                    {{ __('No workflows connect these resources yet.') }}
                </p>
            @else
                <ul class="mt-4 grid gap-3" aria-label="{{ __('Configured cross-app workflows') }}">
                    @foreach ($visibleConnections as $connection)
                        @php
                            $source = $connection->sourceResource;
                            $target = $connection->targetResource;
                            $connectionTone = match ($connection->status) {
                                'active', 'connected' => 'success',
                                'failed', 'error' => 'danger',
                                default => 'warning',
                            };
                            $connectionLabel = $connection->status === 'pending'
                                ? __('Setup pending')
                                : str($connection->status)->headline();
                        @endphp
                        <li class="min-w-0 rounded-panel border border-line bg-surface-muted p-3 sm:p-4">
                            <div class="grid items-center gap-2 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
                                <div class="min-w-0 rounded-control border border-line bg-surface px-3 py-2.5">
                                    <p class="ui-eyebrow">{{ $products[$source?->product] ?? str($source?->product ?? 'application')->headline() }}</p>
                                    <p class="mt-1 truncate text-sm font-bold text-ink">{{ $source?->name ?: str($source?->resource_type ?? 'resource')->headline() }}</p>
                                    @if ($source?->environment?->name)
                                        <p class="mt-1 truncate text-xs text-muted">{{ $source->environment->name }}</p>
                                    @endif
                                </div>

                                <span class="justify-self-center text-primary" aria-hidden="true">
                                    <svg class="h-4 w-4 rotate-90 stroke-2 sm:rotate-0"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                                </span>
                                <span class="sr-only">{{ __('connects to') }}</span>

                                <div class="min-w-0 rounded-control border border-line bg-surface px-3 py-2.5">
                                    <p class="ui-eyebrow">{{ $products[$target?->product] ?? str($target?->product ?? 'application')->headline() }}</p>
                                    <p class="mt-1 truncate text-sm font-bold text-ink">{{ $target?->name ?: str($target?->resource_type ?? 'resource')->headline() }}</p>
                                    @if ($target?->environment?->name)
                                        <p class="mt-1 truncate text-xs text-muted">{{ $target->environment->name }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
                                <ul class="flex flex-wrap gap-1.5" aria-label="{{ __('Workflow behaviors') }}">
                                    @foreach ((array) $connection->capabilities as $capabilityKey)
                                        <li>
                                            <x-signal.ui.badge tone="accent">
                                                {{ \App\Core\Enums\ProjectConnectionCapability::tryFrom($capabilityKey)?->label() ?? str($capabilityKey)->headline() }}
                                            </x-signal.ui.badge>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($connection->last_succeeded_at)
                                        <span class="text-xs text-muted">{{ __('Last synced :date', ['date' => $connection->last_succeeded_at->diffForHumans()]) }}</span>
                                    @endif
                                    <x-signal.ui.badge :tone="$connectionTone">{{ $connectionLabel }}</x-signal.ui.badge>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </x-signal.ui.card>

    @if ($infrastructure !== [])
        <x-signal.blocks.project-infrastructure :snapshots="$infrastructure" :products="$products" />
    @endif
</div>
