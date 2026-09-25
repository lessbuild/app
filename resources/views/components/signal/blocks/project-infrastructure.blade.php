@props(['snapshots' => [], 'products' => []])

@foreach ($snapshots as $product => $snapshot)
    @php
        $label = $products[$product] ?? str($product)->headline();
        $nodes = collect($snapshot->nodes)->keyBy('key');
        $edges = collect($snapshot->edges)->filter(fn ($edge) => $nodes->has($edge->sourceKey) && $nodes->has($edge->targetKey));
    @endphp
    <x-signal.ui.card class="overflow-hidden">
        <div class="border-b border-line px-4 py-4 sm:px-5">
            <h4 class="text-sm font-extrabold text-ink">{{ __(':product infrastructure', ['product' => $label]) }}</h4>
            <p class="mt-1 text-xs leading-5 text-muted">{{ __('Live resources and recent deployments for the selected project and environment.') }}</p>
        </div>

        @if (! $snapshot->available)
            <div class="p-4 sm:p-5">
                <x-signal.ui.alert tone="warning">{{ __('Infrastructure is temporarily unavailable. Resource details will return when access can be confirmed.') }}</x-signal.ui.alert>
            </div>
        @elseif ($nodes->isEmpty())
            <div class="p-4 sm:p-5">
                <x-signal.ui.empty-state :title="__('No infrastructure to show')" :description="__('Link an environment to this project to see its authorized repositories, servers, and deployments.')" />
            </div>
        @else
            <div class="grid gap-3 p-4 sm:p-5 md:grid-cols-2 xl:grid-cols-3" aria-label="{{ __('Infrastructure resources') }}">
                @foreach ($nodes as $node)
                    <x-signal.ui.card tone="muted" :shadow="false" class="min-w-0 p-3">
                        <p class="ui-eyebrow">{{ str($node->kind)->headline() }}</p>
                        <p class="mt-1 break-words text-sm font-bold text-ink">{{ $node->label }}</p>
                        @if ($node->environmentName)
                            <p class="mt-1 text-xs text-muted">{{ $node->environmentName }}</p>
                        @endif
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @if ($node->status)
                                <x-signal.ui.badge tone="neutral">{{ str($node->status)->headline() }}</x-signal.ui.badge>
                            @endif
                            @if ($node->url)
                                <x-signal.ui.button :href="$node->url" variant="secondary" size="sm" :aria-label="__('Open :resource in :product', ['resource' => $node->label, 'product' => $label])">{{ __('Open') }}</x-signal.ui.button>
                            @endif
                        </div>
                    </x-signal.ui.card>
                @endforeach
            </div>

            @if ($edges->isNotEmpty())
                <div class="border-t border-line p-4 sm:p-5">
                    <h5 class="text-sm font-bold text-ink">{{ __('Resource relationships') }}</h5>
                    <ul class="mt-3 grid gap-2 text-sm text-ink">
                        @foreach ($edges as $edge)
                            <li class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-control border border-line px-3 py-2">
                                <span class="break-words font-semibold">{{ $nodes->get($edge->sourceKey)->label }}</span>
                                <span class="text-xs text-muted">{{ $edge->label }}</span>
                                <span class="break-words font-semibold">{{ $nodes->get($edge->targetKey)->label }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="border-t border-line p-4 sm:p-5">
                <x-signal.ui.disclosure :title="__('Show resource list')">
                <x-signal.ui.table :caption="__('Infrastructure resource list')">
                    <x-slot:head>
                        <tr><th scope="col">{{ __('Resource') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Environment') }}</th><th scope="col">{{ __('State') }}</th></tr>
                    </x-slot:head>
                    @foreach ($nodes as $node)
                        <tr>
                            <td>
                                @if ($node->url)
                                    <a href="{{ $node->url }}" class="font-semibold text-primary underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">{{ $node->label }}</a>
                                @else
                                    {{ $node->label }}
                                @endif
                            </td>
                            <td>{{ str($node->kind)->headline() }}</td>
                            <td>{{ $node->environmentName ?? __('Shared resource') }}</td>
                            <td>{{ $node->status ? str($node->status)->headline() : __('Available') }}</td>
                        </tr>
                    @endforeach
                </x-signal.ui.table>
                </x-signal.ui.disclosure>
                @if ($snapshot->truncated)
                    <p class="mt-3 text-xs leading-5 text-muted">{{ __('This overview is limited to 100 resources and recent deployments. Open the app for the complete authorized history.') }}</p>
                @endif
            </div>
        @endif
    </x-signal.ui.card>
@endforeach
