<x-layouts.app>
    <x-layouts.partials.heading
        eyebrow="{{ __('Delivery targets') }}"
        icon="link"
        :title="__('Domains & TLS')"
        :description="__('Manage aliases, redirects, Cloudflare DNS, temporary domains, and certificate health.')"
    />

    @php
        $domains = $websites->flatMap(fn ($website) => $website->domains);
        $attentionDomainCount = $domains->filter(fn ($domain) => $domain->dns_status !== 'active' || $domain->ssl_status !== 'active')->count();
    @endphp

    <x-ui.insights
        id="domain-insights"
        class="mt-6"
        :summary="trans_choice(':count domain configured|:count domains configured', $domains->count(), ['count' => $domains->count()])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat
                :label="__('Websites')"
                :value="$websites->count()"
                :description="__('Websites with domain records available.')"
            />
            <x-ui.stat
                :label="__('Domains')"
                :value="$domains->count()"
                :description="__('Primary, alias, redirect, and temporary hosts.')"
            />
            <x-ui.stat
                :label="__('Temporary')"
                :value="$domains->where('is_temporary', true)->count()"
                :description="__('Shareable temporary domains.')"
            />
            <x-ui.stat
                :label="__('Needs attention')"
                :value="$attentionDomainCount"
                :description="__('DNS or TLS status is not active.')"
            />
        </dl>
    </x-ui.insights>

    @if ($canManage)
        @php
            $domainDialog = request()->query('dialog');
            $addDomainOpen = $domainDialog === 'add-domain'
                || ($domainDialog === null && $errors->hasAny(['hostname', 'type', 'redirect_url']));
            $temporaryDomainOpen = $domainDialog === 'temporary-domain';
        @endphp

        <div class="mt-6 flex flex-wrap gap-3">
            <x-ui.button
                :href="route('domains.index', ['dialog' => 'add-domain'])"
                data-modal-trigger="domain-add-dialog"
                aria-controls="domain-add-dialog"
                aria-expanded="{{ $addDomainOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                {{ __('Add domain') }}
            </x-ui.button>
            <x-ui.button
                :href="route('domains.index', ['dialog' => 'temporary-domain'])"
                data-modal-trigger="temporary-domain-dialog"
                aria-controls="temporary-domain-dialog"
                aria-expanded="{{ $temporaryDomainOpen ? 'true' : 'false' }}"
                variant="secondary"
            >
                {{ __('Issue temporary domain') }}
            </x-ui.button>
        </div>

        <x-dialogs.modal
            id="domain-add-dialog"
            :title="__('Add domain')"
            :description="__('Attach an alias or redirect to an existing website.')"
            :open="$addDomainOpen"
        >
            <form method="POST" action="{{ route('domains.store') }}" class="space-y-4">
                @csrf
                <x-forms.errors name="domain" />
                <x-forms.errors name="dns_provider_id" />
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
                <div class="flex flex-wrap justify-end gap-3 border-t border-primary pt-4">
                    <x-ui.button type="button" variant="ghost" onclick="this.closest('dialog').close('cancel')">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Add domain') }}</x-ui.button>
                </div>
            </form>
        </x-dialogs.modal>

        <x-dialogs.modal
            id="temporary-domain-dialog"
            :title="__('Issue temporary domain')"
            :description="$temporaryBaseDomain ? __('Issue a shareable :domain address.', ['domain' => '*.' . $temporaryBaseDomain]) : __('Set TEMPORARY_APP_DOMAIN to enable this feature.')"
            :open="$temporaryDomainOpen"
        >
            <form method="POST" action="{{ route('domains.temporary') }}" class="space-y-4">
                @csrf
                <x-forms.errors name="domain" />
                <x-forms.errors name="dns_provider_id" />
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
                <div class="flex flex-wrap justify-end gap-3 border-t border-primary pt-4">
                    <x-ui.button type="button" variant="ghost" onclick="this.closest('dialog').close('cancel')">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary" :disabled="! $temporaryBaseDomain">{{ __('Issue domain') }}</x-ui.button>
                </div>
            </form>
        </x-dialogs.modal>
    @endif

    <div id="domain-inventory" class="mt-6 space-y-5">
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
</x-layouts.app>
