@props([
    'balancer',
    'servers',
    'open' => false,
    'id' => null,
])

@php
    $dialogId = $id ?: 'load-balancer-node-'.$balancer->id;
    $nodeFormActive = old('_load_balancer_id') == $balancer->id;
@endphp

<x-dialogs.modal
    :id="$dialogId"
    :title="__('Add application node')"
    :description="__('Choose a distinct application server and configure its upstream capacity.')"
    :open="$open"
>
    <form method="POST" action="{{ route('load-balancers.nodes.store', $balancer) }}" class="grid gap-3 sm:grid-cols-3">
        @csrf
        <input type="hidden" name="_load_balancer_id" value="{{ $balancer->id }}">
        <label class="block sm:col-span-3" for="node-server-{{ $balancer->id }}">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Application node') }}</span>
            <select id="node-server-{{ $balancer->id }}" name="server_id" class="input secondary w-full rounded-md" required>
                <option value="">{{ __('Application node') }}</option>
                @foreach ($servers->where('id', '!=', $balancer->server_id) as $server)
                    <option value="{{ $server->id }}" @selected((string) old('server_id') === (string) $server->id && $nodeFormActive)>{{ $server->label }}</option>
                @endforeach
            </select>
            @if ($nodeFormActive)
                <x-forms.errors name="server_id" />
            @endif
        </label>
        <label class="block" for="node-port-{{ $balancer->id }}">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Port') }}</span>
            <input id="node-port-{{ $balancer->id }}" name="upstream_port" type="number" min="1" max="65535" value="{{ $nodeFormActive ? old('upstream_port', 80) : 80 }}" class="input secondary w-full rounded-md">
            @if ($nodeFormActive)
                <x-forms.errors name="upstream_port" />
            @endif
        </label>
        <label class="block" for="node-weight-{{ $balancer->id }}">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Weight') }}</span>
            <input id="node-weight-{{ $balancer->id }}" name="weight" type="number" min="1" max="10" value="{{ $nodeFormActive ? old('weight', 1) : 1 }}" class="input secondary w-full rounded-md">
            @if ($nodeFormActive)
                <x-forms.errors name="weight" />
            @endif
        </label>
        <div class="flex items-end">
            <x-ui.button type="submit" variant="primary" class="w-full">{{ __('Add') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
