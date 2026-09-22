<x-layouts.app>
    @php
        $domains = $websites->flatMap(fn ($website) => $website->domains);
        $attentionDomainCount = $domains->filter(fn ($domain) => $domain->dns_status !== 'active' || $domain->ssl_status !== 'active')->count();
        $domainDialog = request()->query('dialog');
        $addDomainOpen = $domainDialog === 'add-domain'
            || ($domainDialog === null && $errors->hasAny(['hostname', 'type', 'redirect_url']));
        $temporaryDomainOpen = $domainDialog === 'temporary-domain';
    @endphp

    <x-layouts.partials.heading
        eyebrow="{{ __('Delivery targets') }}"
        icon="link"
        :title="__('Domains & TLS')"
        :description="__('Manage aliases, redirects, Cloudflare DNS, temporary domains, and certificate health.')"
    >
        @if ($canManage)
            <x-slot:buttons>
                <div data-domain-actions class="flex flex-wrap gap-2">
                    <x-ui.button
                        :href="route('domains.index', ['dialog' => 'add-domain'])"
                        data-modal-trigger="domain-add-dialog"
                        aria-controls="domain-add-dialog"
                        aria-expanded="{{ $addDomainOpen ? 'true' : 'false' }}"
                        variant="primary"
                    >{{ __('Add domain') }}</x-ui.button>
                    <x-ui.button
                        :href="route('domains.index', ['dialog' => 'temporary-domain'])"
                        data-modal-trigger="temporary-domain-dialog"
                        aria-controls="temporary-domain-dialog"
                        aria-expanded="{{ $temporaryDomainOpen ? 'true' : 'false' }}"
                        variant="secondary"
                    >{{ __('Issue temporary domain') }}</x-ui.button>
                </div>
            </x-slot:buttons>
        @endif
    </x-layouts.partials.heading>

    <x-ui.local-nav :label="__('Domain sections')">
        <a href="#domain-insights" class="ui-local-nav__link">{{ __('Overview') }}</a>
        <a href="#domain-inventory" class="ui-local-nav__link">{{ __('Inventory') }}</a>
    </x-ui.local-nav>

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
        <x-scenes.domains.add-dialog
            :websites="$websites"
            :dns-providers="$dnsProviders"
            :open="$addDomainOpen"
        />
        <x-scenes.domains.temporary-dialog
            :websites="$websites"
            :dns-providers="$dnsProviders"
            :temporary-base-domain="$temporaryBaseDomain"
            :open="$temporaryDomainOpen"
        />
    @endif

    <div id="domain-inventory" class="ui-inventory-list mt-6 space-y-5 scroll-mt-24">
            @forelse ($websites as $website)
                <section class="ui-panel overflow-hidden" data-domain-website>
                    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-5">
                        <div class="min-w-0">
                            <h2 class="font-black text-ink">{{ $website->name }}</h2>
                            <a href="https://{{ $website->url }}" target="_blank" rel="noopener noreferrer" class="ui-link break-all text-sm">
                                {{ $website->url }}
                            </a>
                        </div>
                        <x-ui.badge>{{ $website->domains->count() }} {{ __('domains') }}</x-ui.badge>
                    </header>

                    <div class="divide-y divide-line">
                        @foreach ($website->domains->sortBy(fn ($domain) => $domain->type === 'primary' ? 0 : 1) as $domain)
                            <article class="flex flex-wrap items-center gap-4 p-4 transition-colors hover:bg-surface-muted sm:p-5">
                                <div class="min-w-0 flex-1">
                                    <p class="break-all font-bold text-ink">{{ $domain->hostname }}</p>
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted">
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
                                        <p class="mt-2 break-all text-xs text-muted">→ {{ $domain->redirect_url }}</p>
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
