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

<x-signal.overlays.modal
    :id="$dialogId"
    :title="__('Add application node')"
    :description="__('Choose a distinct application server and configure its upstream capacity.')"
    :open="$open"
>
    <form method="POST" action="{{ route('load-balancers.nodes.store', $balancer) }}" class="grid gap-3 sm:grid-cols-3">
        @csrf
        <x-signal.ui.input type="hidden" name="_load_balancer_id" value="{{ $balancer->id }}" :restore="false" />
        <label class="block sm:col-span-3" for="node-server-{{ $balancer->id }}">
            <span class="ui-label">{{ __('Application node') }}</span>
            <x-signal.ui.select id="node-server-{{ $balancer->id }}" name="server_id" class="ui-input" required>
                <option value="">{{ __('Application node') }}</option>
                @foreach ($servers->where('id', '!=', $balancer->server_id) as $server)
                    <option value="{{ $server->id }}" @selected((string) old('server_id') === (string) $server->id && $nodeFormActive)>{{ $server->label }}</option>
                @endforeach
            </x-signal.ui.select>
            @if ($nodeFormActive)
                <x-forms.errors name="server_id" />
            @endif
        </label>
        <label class="block" for="node-port-{{ $balancer->id }}">
            <span class="ui-label">{{ __('Port') }}</span>
            <x-signal.ui.input id="node-port-{{ $balancer->id }}" name="upstream_port" type="number" min="1" max="65535" value="{{ $nodeFormActive ? old('upstream_port', 80) : 80 }}" class="ui-input" :restore="false" />
            @if ($nodeFormActive)
                <x-forms.errors name="upstream_port" />
            @endif
        </label>
        <label class="block" for="node-weight-{{ $balancer->id }}">
            <span class="ui-label">{{ __('Weight') }}</span>
            <x-signal.ui.input id="node-weight-{{ $balancer->id }}" name="weight" type="number" min="1" max="10" value="{{ $nodeFormActive ? old('weight', 1) : 1 }}" class="ui-input" :restore="false" />
            @if ($nodeFormActive)
                <x-forms.errors name="weight" />
            @endif
        </label>
        <div class="flex items-end">
            <x-signal.ui.button type="submit" variant="primary" class="w-full">{{ __('Add') }}</x-signal.ui.button>
        </div>
    </form>
</x-signal.overlays.modal>
