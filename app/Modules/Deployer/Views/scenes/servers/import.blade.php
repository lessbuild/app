<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('servers.index')" :title="__('Back to servers')" />

    @if (! $planUsage['plan_available'] || ! $planUsage['limit_configured'])
        <x-signal.ui.alert tone="warning" class="my-4">
            {{ __('We could not confirm this workspace’s Deployer plan and server allowance. Retry shortly or contact support.') }}
        </x-signal.ui.alert>
    @elseif (! $planUsage['allowed'])
        <x-signal.ui.alert tone="warning" class="my-4">
            <p class="font-semibold">{{ __('Your plan’s server limit has been reached') }}</p>
            <x-signal.ui.button :href="route('pricing')" variant="secondary" class="mt-3">{{ __('View plans') }}</x-signal.ui.button>
        </x-signal.ui.alert>
    @endif

    @error('plan')
        <x-signal.ui.alert tone="danger" class="my-4">{{ $message }}</x-signal.ui.alert>
    @enderror

    <div class="mx-auto max-w-4xl">
        <form action="{{ route('servers.import.store') }}" method="POST">
            @csrf
            <x-signal.ui.card class="mt-8 overflow-hidden">
                <div class="border-b border-line px-5 py-5 sm:px-8">
                    <p class="ui-eyebrow">{{ __('Infrastructure') }}</p>
                    <h1 class="mt-1 text-xl font-extrabold text-ink">{{ __('Import an existing server') }}</h1>
                    <p class="mt-1 text-sm text-muted">{{ __('Connect an Ubuntu server you already control. :app will install and configure the selected runtime over SSH.', ['app' => config('app.name')]) }}</p>
                </div>

                <div class="space-y-6 bg-surface px-5 py-5 sm:px-8">
                    <x-signal.ui.alert tone="warning">
                        {{ __('This first step is read-only. :app will verify SSH access, inspect the operating system and existing services, and show the host fingerprint and exact change categories before asking for approval.', ['app' => config('app.name')]) }}
                    </x-signal.ui.alert>

                    <x-signal.ui.input-field
                        id="name"
                        name="name"
                        :label="__('Server name')"
                        maxlength="255"
                        required
                        placeholder="production-1"
                        class="w-full"
                    />

                    <x-signal.ui.select-field
                        id="type"
                        name="type"
                        :label="__('Server type')"
                        required
                        class="w-full"
                    >
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ str($type->value)->headline() }} ({{ implode(', ', $type->installs()) }})</option>
                        @endforeach
                    </x-signal.ui.select-field>

                    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]">
                        <x-signal.ui.input-field
                            id="public_ip"
                            name="public_ip"
                            :label="__('Public IP address')"
                            required
                            inputmode="decimal"
                            placeholder="203.0.113.10"
                            class="w-full"
                        />
                        <x-signal.ui.input-field
                            id="ssh_port"
                            name="ssh_port"
                            :label="__('SSH port')"
                            type="number"
                            min="1"
                            max="65535"
                            :value="22"
                            required
                            class="w-full"
                        />
                    </div>

                    <x-signal.ui.textarea-field
                        id="ssh_private_key"
                        name="ssh_private_key"
                        :label="__('Root SSH private key')"
                        :description="__('The key is encrypted at rest. Password-protected keys are not supported by unattended provisioning.')"
                        rows="9"
                        required
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                        :restore="false"
                        class="w-full font-mono"
                    />
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-8">
                    <x-signal.ui.button :href="route('servers.index')" variant="ghost">{{ __('Cancel') }}</x-signal.ui.button>
                    <x-signal.ui.button type="submit" variant="primary" :disabled="! $planUsage['allowed']">{{ __('Inspect server safely') }}</x-signal.ui.button>
                </div>
            </x-signal.ui.card>
        </form>
    </div>
</x-layouts.app>
