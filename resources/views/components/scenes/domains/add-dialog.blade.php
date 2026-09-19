@props([
    'websites',
    'dnsProviders',
    'open' => false,
])

<x-dialogs.modal
    id="domain-add-dialog"
    :title="__('Add domain')"
    :description="__('Attach an alias or redirect to an existing website.')"
    :open="$open"
>
    <form method="POST" action="{{ route('domains.store') }}" class="space-y-4">
        @csrf
        <x-forms.errors name="domain" />
        <x-forms.errors name="dns_provider_id" />
        <label class="block" for="domain-add-website">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Website') }}</span>
            <select id="domain-add-website" name="website_id" class="input secondary w-full rounded-md" required>
                @foreach ($websites as $website)
                    <option value="{{ $website->id }}" @selected((string) old('website_id') === (string) $website->id)>{{ $website->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="block" for="domain-add-hostname">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Hostname') }}</span>
            <input id="domain-add-hostname" name="hostname" value="{{ old('hostname') }}" placeholder="www.example.com" class="input secondary w-full rounded-md" required>
            <x-forms.errors name="hostname" />
        </label>
        <label class="block" for="domain-add-type">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Behavior') }}</span>
            <select id="domain-add-type" name="type" class="input secondary w-full rounded-md">
                <option value="alias" @selected(old('type', 'alias') === 'alias')>{{ __('Serve application') }}</option>
                <option value="redirect" @selected(old('type') === 'redirect')>{{ __('Redirect') }}</option>
            </select>
            <x-forms.errors name="type" />
        </label>
        <label class="block" for="domain-add-redirect-url">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Redirect destination') }}</span>
            <input id="domain-add-redirect-url" type="url" name="redirect_url" value="{{ old('redirect_url') }}" placeholder="https://example.com" class="input secondary w-full rounded-md">
            <x-forms.errors name="redirect_url" />
        </label>
        <label class="block" for="domain-add-dns-provider">
            <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('DNS automation') }}</span>
            <select id="domain-add-dns-provider" name="dns_provider_id" class="input secondary w-full rounded-md">
                <option value="" @selected(blank(old('dns_provider_id')))>{{ __('Manual DNS') }}</option>
                @foreach ($dnsProviders as $provider)
                    <option value="{{ $provider->id }}" @selected((string) old('dns_provider_id') === (string) $provider->id)>{{ $provider->name }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex flex-wrap justify-end gap-3 border-t border-primary pt-4">
            <x-ui.button type="button" variant="ghost" onclick="this.closest('dialog').close('cancel')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary">{{ __('Add domain') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
