<x-layouts.app title="{{ __('High availability') }}">
    <x-layouts.partials.heading
        icon="cloud"
        :title="__('High availability')"
        :description="__('Route traffic across application nodes with active health checks, weighted capacity, and automatic failover.')"
    />

    @unless ($featureAvailable)
        <div class="ui-alert ui-alert--info mt-6" role="status">
            <strong class="text-primary">{{ __('Business feature') }}</strong>
            <span class="mx-1">·</span>
            {{ __('Upgrade to create and operate high-availability routes.') }}
            <a href="{{ route('pricing') }}" class="ml-1 font-bold text-ternary underline">{{ __('Compare plans') }}</a>
        </div>
    @endunless

    @if ($canManage)
        <form method="POST" action="{{ route('load-balancers.store') }}" class="ui-card mt-8 p-5">
            @csrf
            <div>
                <h2 class="font-black text-primary">{{ __('Create a high-availability route') }}</h2>
                <p class="mt-1 text-sm text-secondary">{{ __('Choose the environment and dedicated edge server before adding application nodes.') }}</p>
            </div>
            <div class="mt-5 grid gap-4 md:grid-cols-2">
                <label class="block" for="load-balancer-environment">
                    <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Environment') }}</span>
                    <select id="load-balancer-environment" name="environment_id" class="input secondary w-full rounded-md" required>
                        <option value="">{{ __('Environment') }}</option>
                        @foreach ($environments as $environment)
                            <option value="{{ $environment->id }}">{{ $environment->project->name }} / {{ $environment->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block" for="load-balancer-server">
                    <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Dedicated server') }}</span>
                    <select id="load-balancer-server" name="server_id" class="input secondary w-full rounded-md" required>
                        <option value="">{{ __('Dedicated load-balancer server') }}</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}">{{ $server->label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block" for="load-balancer-hostname">
                    <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Hostname') }}</span>
                    <input id="load-balancer-hostname" name="hostname" class="input secondary w-full rounded-md" placeholder="app.example.com" required>
                </label>
                <label class="block" for="load-balancer-health-path">
                    <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Health path') }}</span>
                    <input id="load-balancer-health-path" name="health_path" value="/" class="input secondary w-full rounded-md" required>
                </label>
            </div>
            <div class="mt-5">
                <x-ui.button type="submit" variant="primary">{{ __('Create load balancer') }}</x-ui.button>
            </div>
        </form>
    @endif

    <div class="mt-6 grid gap-5 xl:grid-cols-2">
        @forelse ($loadBalancers as $balancer)
            <section class="ui-card p-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-wider text-ternary">{{ $balancer->environment->project->name }} / {{ $balancer->environment->name }}</p>
                        <h2 class="mt-1 break-all text-lg font-black text-primary">{{ $balancer->hostname }}</h2>
                        <p class="mt-1 text-sm text-secondary">{{ $balancer->server->label }}</p>
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

                <div class="mt-5 space-y-2">
                    <div class="flex items-center justify-between gap-3 text-xs font-bold uppercase tracking-wide text-secondary">
                        <span>{{ __('Application nodes') }}</span>
                        <span>{{ $balancer->nodes->count() }}</span>
                    </div>
                    @foreach ($balancer->nodes as $node)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-secondary p-3">
                            <div class="min-w-0">
                                <p class="truncate font-bold text-primary">{{ $node->server->label }}</p>
                                <p class="text-xs text-secondary">{{ $node->server->public_ip }}:{{ $node->upstream_port }} · {{ __('Weight :weight', ['weight' => $node->weight]) }} · {{ ucfirst($node->health_status) }}</p>
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
                </div>

                @if ($canManage)
                    <form method="POST" action="{{ route('load-balancers.nodes.store', $balancer) }}" class="mt-5 grid gap-3 border-t border-primary pt-5 sm:grid-cols-3">
                        @csrf
                        <label class="block sm:col-span-3" for="node-server-{{ $balancer->id }}">
                            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Application node') }}</span>
                            <select id="node-server-{{ $balancer->id }}" name="server_id" class="input secondary w-full rounded-md" required>
                                <option value="">{{ __('Application node') }}</option>
                                @foreach ($servers->where('id', '!=', $balancer->server_id) as $server)
                                    <option value="{{ $server->id }}">{{ $server->label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block" for="node-port-{{ $balancer->id }}">
                            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Port') }}</span>
                            <input id="node-port-{{ $balancer->id }}" name="upstream_port" type="number" min="1" max="65535" value="80" class="input secondary w-full rounded-md">
                        </label>
                        <label class="block" for="node-weight-{{ $balancer->id }}">
                            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Weight') }}</span>
                            <input id="node-weight-{{ $balancer->id }}" name="weight" type="number" min="1" max="10" value="1" class="input secondary w-full rounded-md">
                        </label>
                        <div class="flex items-end">
                            <x-ui.button type="submit" variant="primary" class="w-full">{{ __('Add') }}</x-ui.button>
                        </div>
                    </form>
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
