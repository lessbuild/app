@props([
    'websites',
    'dnsProviders',
    'temporaryBaseDomain' => null,
    'open' => false,
])

<x-dialogs.modal
    id="temporary-domain-dialog"
    :title="__('Issue temporary domain')"
    :description="$temporaryBaseDomain ? __('Issue a shareable :domain address.', ['domain' => '*.' . $temporaryBaseDomain]) : __('Set TEMPORARY_APP_DOMAIN to enable this feature.')"
    :open="$open"
>
    <form method="POST" action="{{ route('domains.temporary') }}" class="space-y-4">
        @csrf
        <x-forms.errors name="domain" />
        <x-forms.errors name="dns_provider_id" />
        <div>
            <label class="ui-label" for="temporary-domain-website">{{ __('Website') }}</label>
            <select id="temporary-domain-website" name="website_id" class="ui-input" required>
                @foreach ($websites as $website)
                    <option value="{{ $website->id }}" @selected((string) old('website_id') === (string) $website->id)>{{ $website->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ui-label" for="temporary-domain-dns-provider">{{ __('DNS provider') }}</label>
            <select id="temporary-domain-dns-provider" name="dns_provider_id" class="ui-input" required>
                <option value="" @selected(blank(old('dns_provider_id')))>{{ __('Select Cloudflare provider') }}</option>
                @foreach ($dnsProviders as $provider)
                    <option value="{{ $provider->id }}" @selected((string) old('dns_provider_id') === (string) $provider->id)>{{ $provider->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-wrap justify-end gap-3 border-t border-line pt-4">
            <x-ui.button type="button" variant="ghost" onclick="this.closest('dialog').close('cancel')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary" :disabled="! $temporaryBaseDomain">{{ __('Issue domain') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
