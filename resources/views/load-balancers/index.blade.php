<x-layouts.app title="{{ __('High availability') }}">
    @php
        $loadBalancerCreateHasErrors = $errors->hasAny(['environment_id', 'server_id', 'hostname', 'health_path']);
        $loadBalancerCreateOpen = (request()->query('dialog') === 'create-route' && ! session()->has('success'))
            || (old('_load_balancer_form') === 'create' && $loadBalancerCreateHasErrors);
        $loadBalancerCreateUrl = route('load-balancers.index', ['dialog' => 'create-route']);
    @endphp

    <x-layouts.partials.heading
        icon="cloud"
        :title="__('High availability')"
        :description="__('Route traffic across application nodes with active health checks, weighted capacity, and automatic failover.')"
    >
        @if ($canManage)
            <x-slot:buttons>
                <x-ui.button
                    href="{{ $loadBalancerCreateUrl }}"
                    data-modal-trigger="load-balancer-create"
                    aria-controls="load-balancer-create"
                    aria-expanded="{{ $loadBalancerCreateOpen ? 'true' : 'false' }}"
                    variant="primary"
                >
                    {{ __('Create route') }}
                </x-ui.button>
            </x-slot:buttons>
        @endif
    </x-layouts.partials.heading>

    @unless ($featureAvailable)
        <div class="ui-alert ui-alert--info mt-6" role="status">
            <strong class="text-ink">{{ __('Business feature') }}</strong>
            <span class="mx-1">·</span>
            {{ __('Upgrade to create and operate high-availability routes.') }}
            <a href="{{ route('pricing') }}" class="ui-link ml-1 font-bold underline">{{ __('Compare plans') }}</a>
        </div>
    @endunless

    @php
        $healthyBalancerCount = $loadBalancers->whereIn('status', ['active', 'healthy', 'ready'])->count();
        $nodeCount = $loadBalancers->sum(fn ($balancer) => $balancer->nodes->count());
        $underprovisionedBalancerCount = $loadBalancers->filter(fn ($balancer) => $balancer->nodes->count() < 2)->count();
    @endphp

    <x-ui.insights
        id="load-balancer-insights"
        class="mt-6"
        :summary="trans_choice(':count high-availability route|:count high-availability routes', $loadBalancers->count(), ['count' => $loadBalancers->count()])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat
                :label="__('Routes')"
                :value="$loadBalancers->count()"
                :description="__('High-availability routes in this workspace.')"
            />
            <x-ui.stat
                :label="__('Healthy or ready')"
                :value="$healthyBalancerCount"
                :description="__('Routes currently able to apply traffic configuration.')"
            />
            <x-ui.stat
                :label="__('Application nodes')"
                :value="$nodeCount"
                :description="__('Configured upstream nodes across routes.')"
            />
            <x-ui.stat
                :label="__('Needs nodes')"
                :value="$underprovisionedBalancerCount"
                :description="__('Routes with fewer than two application nodes.')"
            />
        </dl>
    </x-ui.insights>

    @if ($canManage)
        <x-scenes.load-balancers.create-dialog
            :environments="$environments"
            :servers="$servers"
            :open="$loadBalancerCreateOpen"
        />
    @endif

    <div class="mt-6 grid gap-5 xl:grid-cols-2">
        @forelse ($loadBalancers as $balancer)
            <section class="ui-card p-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="ui-eyebrow">{{ $balancer->environment->project->name }} / {{ $balancer->environment->name }}</p>
                        <h2 class="mt-1 break-all text-lg font-extrabold text-ink">{{ $balancer->hostname }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ $balancer->server->label }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <x-ui.badge tone="{{ in_array($balancer->status, ['active', 'healthy', 'ready'], true) ? 'success' : (in_array($balancer->status, ['failed', 'error'], true) ? 'danger' : 'accent') }}">{{ ucfirst($balancer->status) }}</x-ui.badge>
                        @if ($canManage)
                            <form method="POST" action="{{ route('load-balancers.apply', $balancer) }}">
                                @csrf
                                <x-ui.button type="submit" variant="secondary">{{ __('Apply') }}</x-ui.button>
                            </form>
                        @endif
                    </div>
                </div>

                @php
                    $nodeManagementOpen = $balancer->nodes->count() < 2
                        || in_array($balancer->status, ['failed', 'error'], true)
                        || (old('_load_balancer_id') == $balancer->id && $errors->hasAny(['server_id', 'upstream_port', 'weight']));
                    $nodeDialogId = 'load-balancer-node-'.$balancer->id;
                    $nodeDialogHasErrors = old('_load_balancer_id') == $balancer->id
                        && $errors->hasAny(['server_id', 'upstream_port', 'weight']);
                    $nodeDialogOpen = (request()->query('dialog') === 'add-node'
                        && (string) request()->query('load_balancer_id') === (string) $balancer->id
                        && ! session()->has('success')) || $nodeDialogHasErrors;
                    $nodeDialogUrl = route('load-balancers.index', [
                        'dialog' => 'add-node',
                        'load_balancer_id' => $balancer->id,
                    ]);
                @endphp
                <details id="load-balancer-nodes-{{ $balancer->id }}" class="group mt-5" @if ($nodeManagementOpen) open @endif>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-lg bg-surface-muted p-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus lg:hidden">
                        <span class="text-xs font-bold uppercase tracking-wide text-muted">{{ __('Application nodes') }}</span>
                        <span class="flex items-center gap-2 text-xs font-bold text-muted"><span>{{ $balancer->nodes->count() }}</span><span class="text-xl font-normal transition-transform group-open:rotate-45" aria-hidden="true">+</span></span>
                    </summary>

                    <div class="space-y-2 lg:block">
                        <div class="flex items-center justify-between gap-3 text-xs font-bold uppercase tracking-wide text-muted lg:hidden">
                            <span>{{ __('Application nodes') }}</span>
                            <span>{{ $balancer->nodes->count() }}</span>
                        </div>
                        @foreach ($balancer->nodes as $node)
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-surface-muted p-3">
                                <div class="min-w-0">
                                    <p class="truncate font-bold text-ink">{{ $node->server->label }}</p>
                                    <p class="text-xs text-muted">{{ $node->server->public_ip }}:{{ $node->upstream_port }} · {{ __('Weight :weight', ['weight' => $node->weight]) }} · {{ ucfirst($node->health_status) }}</p>
                                </div>
                                @if ($canManage)
                                    <form method="POST" action="{{ route('load-balancers.nodes.destroy', $node) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="danger">{{ __('Remove') }}</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        @endforeach

                        @if ($canManage)
                            <div class="mt-5 border-t border-line pt-5">
                                <x-ui.button
                                    href="{{ $nodeDialogUrl }}"
                                    data-modal-trigger="{{ $nodeDialogId }}"
                                    aria-controls="{{ $nodeDialogId }}"
                                    aria-expanded="{{ $nodeDialogOpen ? 'true' : 'false' }}"
                                    variant="secondary"
                                >
                                    {{ __('Add application node') }}
                                </x-ui.button>
                            </div>
                        @endif
                    </div>
                </details>

                @if ($canManage)
                    <x-scenes.load-balancers.node-dialog
                        :balancer="$balancer"
                        :servers="$servers"
                        :id="$nodeDialogId"
                        :open="$nodeDialogOpen"
                    />
                @endif
            </section>
        @empty
            <x-ui.empty-state
                class="xl:col-span-2"
                :title="__('No high-availability routes yet')"
                :description="__('Create a route to distribute traffic across application nodes.')"
                icon="cloud"
            />
        @endforelse
    </div>
</x-layouts.app>
