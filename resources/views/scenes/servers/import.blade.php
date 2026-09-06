<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('servers.index')" :title="__('Back to servers')" />

    @if (! $planUsage['allowed'])
        <div class="my-4"><x-alerts.info :title="__('Your plan’s server limit has been reached')" :link="route('pricing')" :anchor="__('View plans')" /></div>
    @endif
    @error('plan')<div class="my-4 rounded border border-red-300 bg-red-50 p-4 text-red-800">{{ $message }}</div>@enderror

    <form action="{{ route('servers.import.store') }}" method="POST">
        @csrf
        <x-forms.section
            title="{{ __('Import an existing server') }}"
            description="{{ __('Connect an Ubuntu server you already control. BuildPusher will install and configure the selected runtime over SSH.') }}"
        >
            <div class="space-y-6 bg-primary px-4 py-5 sm:p-6">
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    {{ __('This first step is read-only. BuildPusher will verify SSH access, inspect the operating system and existing services, and show the host fingerprint and exact change categories before asking for approval.') }}
                </div>
                <div><label for="name" class="block text-sm font-medium text-primary">{{ __('Server name') }}</label><input id="name" name="name" value="{{ old('name') }}" maxlength="255" required class="input secondary mt-1 rounded" placeholder="production-1"><x-forms.errors name="name" /></div>
                <div><label for="type" class="block text-sm font-medium text-primary">{{ __('Server type') }}</label><select id="type" name="type" required class="input secondary mt-1 rounded">@foreach($types as $type)<option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ str($type->value)->headline() }} ({{ implode(', ', $type->installs()) }})</option>@endforeach</select><x-forms.errors name="type" /></div>
                <div class="grid gap-4 sm:grid-cols-[1fr_10rem]"><div><label for="public_ip" class="block text-sm font-medium text-primary">{{ __('Public IP address') }}</label><input id="public_ip" name="public_ip" value="{{ old('public_ip') }}" required inputmode="decimal" class="input secondary mt-1 rounded" placeholder="203.0.113.10"><x-forms.errors name="public_ip" /></div><div><label for="ssh_port" class="block text-sm font-medium text-primary">{{ __('SSH port') }}</label><input id="ssh_port" name="ssh_port" type="number" min="1" max="65535" value="{{ old('ssh_port', 22) }}" required class="input secondary mt-1 rounded"><x-forms.errors name="ssh_port" /></div></div>
                <div><label for="ssh_private_key" class="block text-sm font-medium text-primary">{{ __('Root SSH private key') }}</label><textarea id="ssh_private_key" name="ssh_private_key" rows="9" required autocomplete="off" spellcheck="false" class="input secondary mt-1 rounded font-mono" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----">{{ old('ssh_private_key') }}</textarea><p class="mt-2 text-xs text-secondary">{{ __('The key is encrypted at rest. Password-protected keys are not supported by unattended provisioning.') }}</p><x-forms.errors name="ssh_private_key" /></div>
            </div>
            <x-slot:footer><div class="bg-tertiary px-4 py-3 text-right sm:px-6"><button type="submit" class="button primary disabled:cursor-not-allowed disabled:opacity-50" @disabled(!$planUsage['allowed'])>{{ __('Inspect server safely') }}</button></div></x-slot:footer>
        </x-forms.section>
    </form>
</x-layouts.app>
