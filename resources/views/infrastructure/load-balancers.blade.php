@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Load balancers')" :description="__('A Caddy proxy on one server that spreads a hostname’s traffic across application servers, skipping any that fail the health check.')">
    @foreach (['hostname', 'server_id', 'website_id', 'upstream_port', 'weight'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach

    @if ($balancers->isEmpty())
        <x-signal.ui.empty-state icon="server" :title="__('No load balancers yet')" :description="__('Add a server of the Load balancer type, then send a hostname’s traffic to two or more app servers.')" />
    @endif

    @foreach ($balancers as $balancer)
        <x-signal.ui.card class="grid gap-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-lg font-bold text-ink">{{ $balancer->hostname }}</p>
                    <p class="text-sm text-muted">{{ __('On :server (:ip)', ['server' => $balancer->server->label(), 'ip' => $balancer->server->public_ip ?? '—']) }} · {{ __('health check :path', ['path' => $balancer->health_path]) }}@if ($balancer->website) · <a href="{{ route('infrastructure.websites.show', [$project, $balancer->website->id]) }}" class="font-bold text-primary hover:underline">{{ $balancer->website->name }}</a>@endif</p>
                </div>
                <x-signal.ui.badge :tone="match ($balancer->status) { 'active' => 'success', 'failed', 'removal_failed' => 'danger', default => 'neutral' }">{{ match ($balancer->status) { 'active' => __('Live'), 'failed' => __('Failed'), 'removing' => __('Removing'), 'removal_failed' => __('Removal failed'), default => __('Applying') } }}</x-signal.ui.badge>
            </div>
            @if ($balancer->last_error)
                <p class="text-sm text-danger">{{ $balancer->last_error }}</p>
            @endif

            @if ($balancer->nodes->isEmpty())
                <p class="text-sm text-muted">{{ __('No nodes yet, so visitors get a 503 page.') }}</p>
            @else
                <ul class="divide-y divide-line text-sm">
                    @foreach ($balancer->nodes->sortBy('id') as $node)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                            <span><span class="font-bold">{{ $node->server->label() }}</span> <span class="font-mono text-xs text-muted">{{ $node->server->public_ip ?? '—' }}:{{ $node->upstream_port }}</span> <span class="text-muted">· {{ __('weight :weight', ['weight' => $node->weight]) }}@if (! $node->is_enabled) · {{ __('out of rotation') }}@endif @if ($node->server->provisioning_status !== 'active') · {{ __('server not active') }}@endif</span></span>
                            @if ($canManage && ! $balancer->isRemoving())
                                <div class="flex gap-1">
                                    <form method="POST" action="{{ route('infrastructure.load-balancers.nodes.update', [$project, $balancer->id, $node->id]) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="upstream_port" value="{{ $node->upstream_port }}">
                                        <input type="hidden" name="weight" value="{{ $node->weight }}">
                                        <input type="hidden" name="is_enabled" value="{{ $node->is_enabled ? 0 : 1 }}">
                                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ $node->is_enabled ? __('Take out') : __('Put back') }}</x-signal.ui.button>
                                    </form>
                                    <form method="POST" action="{{ route('infrastructure.load-balancers.nodes.destroy', [$project, $balancer->id, $node->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    @if (in_array($balancer->status, ['failed', 'removal_failed'], true))
                        <form method="POST" action="{{ route('infrastructure.load-balancers.retry', [$project, $balancer->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Retry') }}</x-signal.ui.button></form>
                    @endif
                    @if ($balancer->status !== 'removing')
                        <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="delete-balancer-{{ $balancer->id }}">{{ __('Delete') }}</x-signal.ui.button>
                        <x-signal.overlays.delete-confirmation :id="'delete-balancer-'.$balancer->id" :route="route('infrastructure.load-balancers.destroy', [$project, $balancer->id])" :title="__('Delete the load balancer for :hostname?', ['hostname' => $balancer->hostname])" :description="__('Its Caddy site is removed from :server; the app servers aren’t touched.', ['server' => $balancer->server->label()])" :submit-label="__('Delete load balancer')" />
                    @endif
                </div>
                @unless ($balancer->isRemoving())
                    <div><x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'add-node-'.$balancer->id])" variant="secondary" size="sm" data-modal-trigger="add-node-{{ $balancer->id }}">{{ __('Add a node') }}</x-signal.ui.button></div>
                    <x-signal.overlays.modal :id="'add-node-'.$balancer->id" :title="__('Add a node to :balancer', ['balancer' => $balancer->hostname])" :description="__('Traffic is shared between nodes by weight.')">
                    <form method="POST" action="{{ route('infrastructure.load-balancers.nodes.store', [$project, $balancer->id]) }}" class="grid items-start gap-4 sm:grid-cols-3">
                        @csrf
                        <input type="hidden" name="_modal" value="add-node-{{ $balancer->id }}">
                        <x-signal.ui.select-field :id="'node-server-'.$balancer->id" name="server_id" :label="__('Server')">
                            @foreach ($servers->where('id', '!=', $balancer->server_id)->whereNotIn('id', $balancer->nodes->pluck('server_id')) as $server)
                                <option value="{{ $server->id }}">{{ $server->label() }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                        <x-signal.ui.input-field :id="'node-port-'.$balancer->id" name="upstream_port" type="number" min="1" max="65535" :label="__('Port')" value="80" required />
                        <x-signal.ui.input-field :id="'node-weight-'.$balancer->id" name="weight" type="number" min="1" max="10" :label="__('Weight')" value="1" required />
                        <div class="flex justify-end sm:col-span-3"><x-signal.ui.button type="submit" variant="primary">{{ __('Add node') }}</x-signal.ui.button></div>
                    </form>
                    </x-signal.overlays.modal>
                    <x-signal.ui.disclosure :title="__('Edit')">
                        <form method="POST" action="{{ route('infrastructure.load-balancers.update', [$project, $balancer->id]) }}" class="grid items-start gap-4 sm:grid-cols-3">
                            @csrf
                            @method('PUT')
                            @include('infrastructure._load-balancer-fields', ['balancer' => $balancer])
                            <div class="sm:col-span-3"><x-signal.ui.button type="submit" variant="primary">{{ __('Save load balancer') }}</x-signal.ui.button></div>
                        </form>
                    </x-signal.ui.disclosure>
                @endunless
            @endif
        </x-signal.ui.card>
    @endforeach

    @if ($canCreate)
        <x-slot:actions>
            <x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'add-load-balancer'])" variant="primary" data-modal-trigger="add-load-balancer">{{ __('Add a load balancer') }}</x-signal.ui.button>
        </x-slot:actions>
        <x-signal.overlays.modal id="add-load-balancer" :title="__('Add a load balancer')" :description="__('Point the hostname’s DNS at the proxy server; Caddy gets its certificate. Nodes are reached over HTTP on their public address.')">
            <form method="POST" action="{{ route('infrastructure.load-balancers.store', $project) }}" class="grid items-start gap-4 sm:grid-cols-3">
                @csrf
                <input type="hidden" name="_modal" value="add-load-balancer">
                <x-signal.ui.select-field id="balancer-server" name="server_id" :label="__('Proxy server')">
                    @forelse ($proxyServers as $server)
                        <option value="{{ $server->id }}" @selected((int) old('server_id') === $server->id)>{{ $server->label() }} ({{ $server->type->label() }})</option>
                    @empty
                        <option value="">{{ __('No active server runs Caddy') }}</option>
                    @endforelse
                </x-signal.ui.select-field>
                @include('infrastructure._load-balancer-fields', ['balancer' => null])
                <div class="flex justify-end sm:col-span-3"><x-signal.ui.button type="submit" variant="primary" :disabled="$proxyServers->isEmpty()">{{ __('Add load balancer') }}</x-signal.ui.button></div>
            </form>
        </x-signal.overlays.modal>
    @elseif ($canManage)
        <x-signal.ui.alert tone="info">{{ __('Load balancers come with the Business Deploy plan and above.') }}</x-signal.ui.alert>
    @endif
</x-signal.layouts.project>
