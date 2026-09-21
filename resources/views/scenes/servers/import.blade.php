<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('servers.index')" :title="__('Back to servers')" />

    @if (! $planUsage['allowed'])
        <x-ui.alert tone="warning" class="my-4">
            <p class="font-semibold">{{ __('Your plan’s server limit has been reached') }}</p>
            <x-ui.button :href="route('pricing')" variant="secondary" class="mt-3">{{ __('View plans') }}</x-ui.button>
        </x-ui.alert>
    @endif

    @error('plan')
        <x-ui.alert tone="danger" class="my-4">{{ $message }}</x-ui.alert>
    @enderror

    <div class="mx-auto max-w-4xl">
        <form action="{{ route('servers.import.store') }}" method="POST">
            @csrf
            <x-ui.card class="mt-8 overflow-hidden">
                <div class="border-b border-primary px-5 py-5 sm:px-8">
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Infrastructure') }}</p>
                    <h1 class="mt-1 text-xl font-black text-primary">{{ __('Import an existing server') }}</h1>
                    <p class="mt-1 text-sm text-secondary">{{ __('Connect an Ubuntu server you already control. :app will install and configure the selected runtime over SSH.', ['app' => config('app.name')]) }}</p>
                </div>

                <div class="space-y-6 bg-primary px-5 py-5 sm:px-8">
                    <x-ui.alert tone="warning">
                        {{ __('This first step is read-only. :app will verify SSH access, inspect the operating system and existing services, and show the host fingerprint and exact change categories before asking for approval.', ['app' => config('app.name')]) }}
                    </x-ui.alert>

                    <div>
                        <label for="name" class="block text-sm font-semibold text-primary">{{ __('Server name') }}</label>
                        <input id="name" name="name" value="{{ old('name') }}" maxlength="255" required class="input secondary mt-2 w-full rounded-lg" placeholder="production-1">
                        <x-forms.errors name="name" />
                    </div>

                    <div>
                        <label for="type" class="block text-sm font-semibold text-primary">{{ __('Server type') }}</label>
                        <select id="type" name="type" required class="input secondary mt-2 w-full rounded-lg">
                            @foreach ($types as $type)
                                <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ str($type->value)->headline() }} ({{ implode(', ', $type->installs()) }})</option>
                            @endforeach
                        </select>
                        <x-forms.errors name="type" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]">
                        <div>
                            <label for="public_ip" class="block text-sm font-semibold text-primary">{{ __('Public IP address') }}</label>
                            <input id="public_ip" name="public_ip" value="{{ old('public_ip') }}" required inputmode="decimal" class="input secondary mt-2 w-full rounded-lg" placeholder="203.0.113.10">
                            <x-forms.errors name="public_ip" />
                        </div>
                        <div>
                            <label for="ssh_port" class="block text-sm font-semibold text-primary">{{ __('SSH port') }}</label>
                            <input id="ssh_port" name="ssh_port" type="number" min="1" max="65535" value="{{ old('ssh_port', 22) }}" required class="input secondary mt-2 w-full rounded-lg">
                            <x-forms.errors name="ssh_port" />
                        </div>
                    </div>

                    <div>
                        <label for="ssh_private_key" class="block text-sm font-semibold text-primary">{{ __('Root SSH private key') }}</label>
                        <textarea id="ssh_private_key" name="ssh_private_key" rows="9" required autocomplete="off" spellcheck="false" class="input secondary mt-2 w-full rounded-lg font-mono" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----">{{ old('ssh_private_key') }}</textarea>
                        <p class="mt-2 text-xs text-secondary">{{ __('The key is encrypted at rest. Password-protected keys are not supported by unattended provisioning.') }}</p>
                        <x-forms.errors name="ssh_private_key" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-primary bg-secondary px-5 py-4 sm:px-8">
                    <x-ui.button :href="route('servers.index')" variant="ghost">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary" :disabled="! $planUsage['allowed']">{{ __('Inspect server safely') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    </div>
</x-layouts.app>
