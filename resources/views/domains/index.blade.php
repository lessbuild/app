<x-layouts.app>
    <x-layouts.partials.heading
        icon="link"
        :title="__('Domains & TLS')"
        :description="__('Manage aliases, redirects, Cloudflare DNS, temporary domains, and certificate health.')"
    />

    <div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-5">
            @forelse ($websites as $website)
                <section class="ui-card overflow-hidden">
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-primary p-5">
                        <div class="min-w-0">
                            <h2 class="font-black text-primary">{{ $website->name }}</h2>
                            <a href="https://{{ $website->url }}" target="_blank" rel="noopener noreferrer" class="break-all text-sm text-ternary">
                                {{ $website->url }}
                            </a>
                        </div>
                        <x-ui.badge>{{ $website->domains->count() }} {{ __('domains') }}</x-ui.badge>
                    </header>

                    <div class="divide-y divide-primary">
                        @foreach ($website->domains->sortBy(fn ($domain) => $domain->type === 'primary' ? 0 : 1) as $domain)
                            <article class="flex flex-wrap items-center gap-4 p-4">
                                <div class="min-w-0 flex-1">
                                    <p class="break-all font-bold text-primary">{{ $domain->hostname }}</p>
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-secondary">
                                        <x-ui.badge>{{ ucfirst($domain->type) }}</x-ui.badge>
                                        @if ($domain->is_temporary)
                                            <x-ui.badge tone="warning">{{ __('Temporary') }}</x-ui.badge>
                                        @endif
                                        <span>{{ __('DNS :status', ['status' => $domain->dns_status]) }}</span>
                                        <span>{{ __('TLS :status', ['status' => $domain->ssl_status]) }}</span>
                                        @if ($domain->certificate_expires_at)
                                            <span>{{ __('Expires :date', ['date' => $domain->certificate_expires_at->toDateString()]) }}</span>
                                        @endif
                                    </div>
                                    @if ($domain->redirect_url)
                                        <p class="mt-2 break-all text-xs text-secondary">→ {{ $domain->redirect_url }}</p>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($domain->dnsProvider)
                                        <form method="POST" action="{{ route('domains.sync', $domain) }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="secondary">{{ __('Sync DNS') }}</x-ui.button>
                                        </form>
                                    @endif
                                    @if ($domain->type !== 'primary')
                                        <form method="POST" action="{{ route('domains.destroy', $domain) }}">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" variant="danger">{{ __('Remove') }}</x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @empty
                <x-lists.empty
                    :title="__('No websites')"
                    :description="__('Create a website before attaching domains.')"
                    icon="link"
                />
            @endforelse
        </div>

        @if ($canManage)
            <aside class="space-y-5">
                @php
                    $domainManagementOpen = $errors->hasAny([
                        'domain',
                        'hostname',
                        'type',
                        'redirect_url',
                        'dns_provider_id',
                    ]);
                @endphp
                <details id="domain-management" class="group grid gap-5 lg:block" @if ($domainManagementOpen) open @endif>
                    <summary class="ui-card flex cursor-pointer list-none items-start justify-between gap-4 p-5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 lg:hidden">
                        <span>
                            <span class="block font-black text-primary">{{ __('Add or issue domains') }}</span>
                            <span class="mt-1 block text-sm text-secondary">{{ __('Attach aliases, redirects, or a temporary shareable address.') }}</span>
                        </span>
                        <span class="shrink-0 text-xl text-secondary transition-transform group-open:rotate-45" aria-hidden="true">+</span>
                    </summary>

                    <div class="ui-card overflow-hidden lg:block">
                        <div class="border-b border-primary p-5">
                            <h2 class="font-black text-primary">{{ __('Add domain') }}</h2>
                            <p class="mt-1 text-sm text-secondary">{{ __('Attach an alias or redirect to an existing website.') }}</p>
                            <x-forms.errors name="domain" />
                            <x-forms.errors name="dns_provider_id" />

                            <form method="POST" action="{{ route('domains.store') }}" class="mt-5 space-y-4">
                                @csrf
                                <label class="block" for="domain-website">
                                    <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Website') }}</span>
                                    <select id="domain-website" name="website_id" class="input secondary w-full rounded-md" required>
                                        @foreach ($websites as $website)
                                            <option value="{{ $website->id }}" @selected((string) old('website_id') === (string) $website->id)>{{ $website->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block" for="domain-hostname">
                                    <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Hostname') }}</span>
                                    <input id="domain-hostname" name="hostname" value="{{ old('hostname') }}" placeholder="www.example.com" class="input secondary w-full rounded-md" required>
                                    <x-forms.errors name="hostname" />
                                </label>
                                <label class="block" for="domain-type">
                                    <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Behavior') }}</span>
                                    <select id="domain-type" name="type" class="input secondary w-full rounded-md">
                                        <option value="alias" @selected(old('type', 'alias') === 'alias')>{{ __('Serve application') }}</option>
                                        <option value="redirect" @selected(old('type') === 'redirect')>{{ __('Redirect') }}</option>
                                    </select>
                                    <x-forms.errors name="type" />
                                </label>
                                <label class="block" for="domain-redirect-url">
                                    <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Redirect destination') }}</span>
                                    <input id="domain-redirect-url" type="url" name="redirect_url" value="{{ old('redirect_url') }}" placeholder="https://example.com" class="input secondary w-full rounded-md">
                                    <x-forms.errors name="redirect_url" />
                                </label>
                                <label class="block" for="domain-dns-provider">
                                    <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('DNS automation') }}</span>
                                    <select id="domain-dns-provider" name="dns_provider_id" class="input secondary w-full rounded-md">
                                        <option value="" @selected(blank(old('dns_provider_id')))>{{ __('Manual DNS') }}</option>
                                        @foreach ($dnsProviders as $provider)
                                            <option value="{{ $provider->id }}" @selected((string) old('dns_provider_id') === (string) $provider->id)>{{ $provider->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <x-ui.button type="submit" variant="primary" class="w-full">{{ __('Add domain') }}</x-ui.button>
                            </form>
                        </div>

                        <div class="p-5">
                            <h2 class="font-black text-primary">{{ __('Temporary domain') }}</h2>
                            <p class="mt-1 text-sm text-secondary">
                                {{ $temporaryBaseDomain ? __('Issue a shareable :domain address.', ['domain' => '*.' . $temporaryBaseDomain]) : __('Set TEMPORARY_APP_DOMAIN to enable this feature.') }}
                            </p>

                            <form method="POST" action="{{ route('domains.temporary') }}" class="mt-5 space-y-4">
                                @csrf
                                <label class="block" for="temporary-website">
                                    <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Website') }}</span>
                                    <select id="temporary-website" name="website_id" class="input secondary w-full rounded-md" required>
                                        @foreach ($websites as $website)
                                            <option value="{{ $website->id }}" @selected((string) old('website_id') === (string) $website->id)>{{ $website->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block" for="temporary-dns-provider">
                                    <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('DNS provider') }}</span>
                                    <select id="temporary-dns-provider" name="dns_provider_id" class="input secondary w-full rounded-md" required>
                                        <option value="" @selected(blank(old('dns_provider_id')))>{{ __('Select Cloudflare provider') }}</option>
                                        @foreach ($dnsProviders as $provider)
                                            <option value="{{ $provider->id }}" @selected((string) old('dns_provider_id') === (string) $provider->id)>{{ $provider->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <x-ui.button type="submit" variant="primary" class="w-full" :disabled="! $temporaryBaseDomain">{{ __('Issue domain') }}</x-ui.button>
                            </form>
                        </div>
                    </div>
                </details>
            </aside>
        @endif
    </div>
</x-layouts.app>
