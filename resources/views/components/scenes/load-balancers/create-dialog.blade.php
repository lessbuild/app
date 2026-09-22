@props([
    'environments',
    'servers',
    'open' => false,
])

<x-dialogs.modal
    id="load-balancer-create"
    :title="__('Create a high-availability route')"
    :description="__('Choose the environment and dedicated edge server before adding application nodes.')"
    :open="$open"
>
    <form method="POST" action="{{ route('load-balancers.store') }}" class="grid gap-4 md:grid-cols-2">
        @csrf
        <input type="hidden" name="_load_balancer_form" value="create">
        <label class="block" for="load-balancer-create-environment">
            <span class="ui-label">{{ __('Environment') }}</span>
            <select id="load-balancer-create-environment" name="environment_id" class="ui-input" required>
                <option value="">{{ __('Environment') }}</option>
                @foreach ($environments as $environment)
                    <option value="{{ $environment->id }}" @selected((string) old('environment_id') === (string) $environment->id)>{{ $environment->project->name }} / {{ $environment->name }}</option>
                @endforeach
            </select>
            <x-forms.errors name="environment_id" />
        </label>
        <label class="block" for="load-balancer-create-server">
            <span class="ui-label">{{ __('Dedicated server') }}</span>
            <select id="load-balancer-create-server" name="server_id" class="ui-input" required>
                <option value="">{{ __('Dedicated load-balancer server') }}</option>
                @foreach ($servers as $server)
                    <option value="{{ $server->id }}" @selected((string) old('server_id') === (string) $server->id)>{{ $server->label }}</option>
                @endforeach
            </select>
            <x-forms.errors name="server_id" />
        </label>
        <label class="block" for="load-balancer-create-hostname">
            <span class="ui-label">{{ __('Hostname') }}</span>
            <input id="load-balancer-create-hostname" name="hostname" value="{{ old('hostname') }}" class="ui-input" placeholder="app.example.com" required>
            <x-forms.errors name="hostname" />
        </label>
        <label class="block" for="load-balancer-create-health-path">
            <span class="ui-label">{{ __('Health path') }}</span>
            <input id="load-balancer-create-health-path" name="health_path" value="{{ old('health_path', '/') }}" class="ui-input" required>
            <x-forms.errors name="health_path" />
        </label>
        <div class="md:col-span-2">
            <x-ui.button type="submit" variant="primary">{{ __('Create load balancer') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
