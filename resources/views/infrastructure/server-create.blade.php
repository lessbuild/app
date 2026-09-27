@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Create a server')" :description="__('The server provisions itself with the software its type needs. It takes about ten minutes.')">
    @error('plan')<x-signal.ui.alert tone="warning" role="alert">{{ $message }}</x-signal.ui.alert>@enderror

    @if ($providers->isEmpty())
        <x-signal.ui.empty-state icon="server" :title="__('Connect a cloud provider first')" :description="__('Add a DigitalOcean, Hetzner Cloud or Vultr token on the account’s Providers page.')">
            <x-slot:action><x-signal.ui.button :href="route('account.providers')" variant="secondary">{{ __('Providers') }}</x-signal.ui.button></x-slot:action>
        </x-signal.ui.empty-state>
    @else
        <form method="GET" action="{{ route('infrastructure.servers.create', $project) }}" class="flex flex-wrap items-end gap-3">
            <x-signal.ui.select-field name="provider" :label="__('Provider')">
                @foreach ($providers as $option)
                    <option value="{{ $option->id }}" @selected($provider?->id === $option->id)>{{ $option->name }} · {{ $option->type->label() }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.button type="submit" variant="secondary">{{ __('Load regions and sizes') }}</x-signal.ui.button>
        </form>

        @if ($catalogError)
            <x-signal.ui.alert tone="danger" role="alert">{{ $catalogError }}</x-signal.ui.alert>
        @elseif ($catalog !== null)
            <x-signal.ui.card>
                <form method="POST" action="{{ route('infrastructure.servers.store', $project) }}" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
                    @csrf
                    <input type="hidden" name="provider_id" value="{{ $provider->id }}">
                    <x-signal.ui.input-field name="name" :label="__('Name')" maxlength="31" placeholder="web-1" :description="__('Letters, numbers and dashes. Used as the hostname.')" required />
                    <x-signal.ui.select-field name="type" :label="__('Type')" required>
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}" @selected(old('type', 'app') === $type->value)>{{ $type->label() }} · {{ implode(', ', $type->installs()) }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    @foreach (['region' => __('Region'), 'size' => __('Size'), 'image' => __('Ubuntu image')] as $field => $label)
                        <x-signal.ui.select-field :name="$field" :label="$label" required>
                            @foreach ($catalog[$field === 'region' ? 'regions' : ($field === 'size' ? 'sizes' : 'images')] as $choice)
                                <option value="{{ $choice['id'] }}" @selected(old($field) === $choice['id'])>{{ $choice['label'] }}</option>
                            @endforeach
                        </x-signal.ui.select-field>
                    @endforeach
                    <p class="text-sm text-muted sm:col-span-2">{{ __('The provider bills you for the server. A new SSH key is made for it; the root and MySQL passwords are shown once, after creation.') }}</p>
                    <div class="flex flex-wrap gap-3 sm:col-span-2">
                        <x-signal.ui.button type="submit" variant="primary">{{ __('Create server') }}</x-signal.ui.button>
                        <x-signal.ui.button :href="route('infrastructure.servers', $project)" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>
                    </div>
                </form>
            </x-signal.ui.card>
        @endif
    @endif
</x-signal.layouts.project>
