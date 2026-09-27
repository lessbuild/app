@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Import a server')" :description="__('We connect over SSH, look around without changing anything, and show what we found before you confirm.')">
    @foreach (['plan', 'connection'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach
    <x-signal.ui.card>
        <form method="POST" action="{{ route('infrastructure.imports.store', $project) }}" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
            @csrf
            <x-signal.ui.input-field name="name" :label="__('Name')" maxlength="31" placeholder="legacy-web" required />
            <x-signal.ui.select-field name="type" :label="__('Type')" required>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected(old('type', 'app') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.input-field name="public_ip" :label="__('Public IP address')" placeholder="203.0.113.10" required />
            <x-signal.ui.input-field name="ssh_port" type="number" :label="__('SSH port')" value="22" min="1" max="65535" required />
            <div class="sm:col-span-2">
                <x-signal.ui.textarea-field name="ssh_private_key" :label="__('Root SSH private key')" rows="6" :restore="false" :description="__('An unencrypted key that logs in as root. It’s stored encrypted and used for provisioning and later operations.')" required />
            </div>
            <p class="text-sm text-muted sm:col-span-2">{{ __('Supported: Ubuntu :versions on x86-64 or ARM64. Provisioning may reconfigure or restart existing services.', ['versions' => implode(', ', config('infrastructure.supported_ubuntu_versions'))]) }}</p>
            <div class="flex flex-wrap gap-3 sm:col-span-2">
                <x-signal.ui.button type="submit" variant="primary">{{ __('Inspect server') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('infrastructure.servers', $project)" variant="quiet">{{ __('Cancel') }}</x-signal.ui.button>
            </div>
        </form>
    </x-signal.ui.card>
</x-signal.layouts.project>
