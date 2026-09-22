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
        <div>
            <label class="ui-label" for="domain-add-website">{{ __('Website') }}</label>
            <select id="domain-add-website" name="website_id" class="ui-input" required>
                @foreach ($websites as $website)
                    <option value="{{ $website->id }}" @selected((string) old('website_id') === (string) $website->id)>{{ $website->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ui-label" for="domain-add-hostname">{{ __('Hostname') }}</label>
            <input id="domain-add-hostname" name="hostname" value="{{ old('hostname') }}" placeholder="www.example.com" class="ui-input" required>
            <x-forms.errors name="hostname" />
        </div>
        <div>
            <label class="ui-label" for="domain-add-type">{{ __('Behavior') }}</label>
            <select id="domain-add-type" name="type" class="ui-input">
                <option value="alias" @selected(old('type', 'alias') === 'alias')>{{ __('Serve application') }}</option>
                <option value="redirect" @selected(old('type') === 'redirect')>{{ __('Redirect') }}</option>
            </select>
            <x-forms.errors name="type" />
        </div>
        <div>
            <label class="ui-label" for="domain-add-redirect-url">{{ __('Redirect destination') }}</label>
            <input id="domain-add-redirect-url" type="url" name="redirect_url" value="{{ old('redirect_url') }}" placeholder="https://example.com" class="ui-input">
            <x-forms.errors name="redirect_url" />
        </div>
        <div>
            <label class="ui-label" for="domain-add-dns-provider">{{ __('DNS automation') }}</label>
            <select id="domain-add-dns-provider" name="dns_provider_id" class="ui-input">
                <option value="" @selected(blank(old('dns_provider_id')))>{{ __('Manual DNS') }}</option>
                @foreach ($dnsProviders as $provider)
                    <option value="{{ $provider->id }}" @selected((string) old('dns_provider_id') === (string) $provider->id)>{{ $provider->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-wrap justify-end gap-3 border-t border-line pt-4">
            <x-ui.button type="button" variant="ghost" onclick="this.closest('dialog').close('cancel')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary">{{ __('Add domain') }}</x-ui.button>
        </div>
    </form>
</x-dialogs.modal>
