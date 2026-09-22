@props([
    'title' => null,
    'description' => null,
])

@php
    $resolvedTitle = $title ?: app(\App\View\PageTitle::class)->for(request()->route());
    $applicationCreateDialogOpen = request()->query('dialog') === 'create-application';
    $applicationCreateDialogUrl = request()->url().'?dialog=create-application';
    $applicationCreateDialogCancelUrl = request()->url();
    $applicationCreateDialogHosted = ! request()->routeIs('dashboard') && ! request()->routeIs('projects.index') && ! request()->routeIs('projects.create');
    /*
        Quick-create links intentionally keep the current path but not arbitrary
        query input. The current page's own filters can contain rejected values;
        rendering those values into every shared dialog link would leak them into
        the page and into nested modal URLs. Page-specific actions preserve their
        own normalized query state where it is meaningful.
    */
    $quickCreateReturnUrl = request()->url();
    $quickCreateDialog = request()->query('dialog');
    $providerCreateDialogHosted = ! request()->routeIs('dashboard', 'providers.index', 'providers.create');
    $serverCreateDialogHosted = ! request()->routeIs('dashboard', 'servers.index', 'servers.create');
    $websiteCreateDialogHosted = ! request()->routeIs('dashboard', 'websites.index', 'websites.create');
    $repositoryCreateDialogHosted = ! request()->routeIs('dashboard', 'repositories.index', 'repositories.create');
    $providerCreateDialogOpen = $providerCreateDialogHosted && $quickCreateDialog === 'create-provider';
    $serverCreateDialogOpen = $serverCreateDialogHosted && $quickCreateDialog === 'create-server';
    $websiteCreateDialogOpen = $websiteCreateDialogHosted && $quickCreateDialog === 'create-website';
    $repositoryCreateDialogOpen = $repositoryCreateDialogHosted && $quickCreateDialog === 'create-repository';
    $providerCreateDialogUrl = (string) \Illuminate\Support\Uri::of($quickCreateReturnUrl)->withQuery(['dialog' => 'create-provider']);
    $serverCreateDialogUrl = (string) \Illuminate\Support\Uri::of($quickCreateReturnUrl)->withQuery(['dialog' => 'create-server']);
    $websiteCreateDialogUrl = (string) \Illuminate\Support\Uri::of($quickCreateReturnUrl)->withQuery(['dialog' => 'create-website']);
    $repositoryCreateDialogUrl = (string) \Illuminate\Support\Uri::of($quickCreateReturnUrl)->withQuery(['dialog' => 'create-repository']);
    $providerCreateContentUrl = route('dialogs.create', ['resource' => 'provider', 'return_to' => $quickCreateReturnUrl]);
    $serverCreateContentUrl = route('dialogs.create', ['resource' => 'server', 'return_to' => $quickCreateReturnUrl]);
    $websiteCreateContentUrl = route('dialogs.create', ['resource' => 'website', 'return_to' => $quickCreateReturnUrl]);
    $repositoryCreateContentUrl = route('dialogs.create', ['resource' => 'repository', 'return_to' => $quickCreateReturnUrl]);
    $serverCreateDialogData = $creationDialogData['server'] ?? null;
    $websiteCreateDialogData = $creationDialogData['website'] ?? null;
    $repositoryCreateDialogData = $creationDialogData['repository'] ?? null;
@endphp

<x-layouts.core :title="$resolvedTitle" :description="$description">
    <a href="#main-content" class="ui-skip-link">
        {{ __('Skip to main content') }}
    </a>
    <div
        data-mobile-shell
        class="app-shell flex flex-wrap overflow-x-hidden pb-[calc(4.5rem+env(safe-area-inset-bottom))] lg:pb-0"
        x-data="{
            menu: false,
            palette: false,
            paletteQuery: '',
            paletteIndex: -1,
            lastPaletteTrigger: null,
            workspaceSearchTimer: null,
            workspaceSearchRequest: null,
            workspaceSearchSequence: 0,
            workspaceSearchResults: '',
            workspaceSearchLoading: false,
            workspaceSearchError: false,
            paletteLinks() {
                return [...(this.$refs.paletteResults?.querySelectorAll('[data-palette-item]') ?? [])]
                    .filter((element) => element.offsetParent !== null);
            },
            movePalette(delta) {
                const links = this.paletteLinks();
                if (!links.length) {
                    this.paletteIndex = -1;
                    this.$refs.paletteInput.focus();
                    return;
                }
                if (this.paletteIndex < 0) {
                    this.paletteIndex = delta > 0 ? 0 : links.length - 1;
                } else {
                    this.paletteIndex = (this.paletteIndex + delta + links.length) % links.length;
                }
                links[this.paletteIndex]?.focus();
            },
            movePaletteTo(index) {
                const links = this.paletteLinks();
                if (!links.length) {
                    this.paletteIndex = -1;
                    this.$refs.paletteInput.focus();
                    return;
                }
                this.paletteIndex = Math.min(Math.max(index, 0), links.length - 1);
                links[this.paletteIndex]?.focus();
            },
            resetPaletteSelection() {
                this.paletteIndex = -1;
            },
            openPalette(trigger = null) {
                this.palette = true;
                this.lastPaletteTrigger = trigger;
                this.paletteQuery = '';
                this.paletteIndex = -1;
                this.workspaceSearchResults = '';
                this.workspaceSearchError = false;
                this.workspaceSearchLoading = false;
                this.workspaceSearchSequence += 1;
                this.workspaceSearchRequest?.abort();
                this.$nextTick(() => this.$refs.paletteInput.focus());
            },
            closePalette() {
                this.palette = false;
                this.workspaceSearchRequest?.abort();
                this.workspaceSearchSequence += 1;
                this.restorePaletteFocus();
            },
            queueWorkspaceSearch() {
                window.clearTimeout(this.workspaceSearchTimer);
                this.workspaceSearchRequest?.abort();
                this.workspaceSearchSequence += 1;
                const sequence = this.workspaceSearchSequence;
                const query = this.paletteQuery.trim();
                this.workspaceSearchResults = '';
                this.workspaceSearchError = false;

                if (query === '') {
                    this.workspaceSearchLoading = false;
                    return;
                }

                this.workspaceSearchLoading = true;
                this.workspaceSearchTimer = window.setTimeout(async () => {
                    const controller = new AbortController();
                    this.workspaceSearchRequest = controller;
                    const url = new URL('{{ route('search.index') }}', window.location.href);
                    url.searchParams.set('q', query);
                    url.searchParams.set('fragment', 'workspace');

                    try {
                        const response = await fetch(url, {
                            signal: controller.signal,
                            headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                        });

                        if (!response.ok) {
                            throw new Error(`Workspace search failed with ${response.status}`);
                        }

                        const body = await response.text();
                        if (sequence !== this.workspaceSearchSequence) {
                            return;
                        }

                        this.workspaceSearchResults = body;
                    } catch (error) {
                        if (error.name !== 'AbortError' && sequence === this.workspaceSearchSequence) {
                            this.workspaceSearchError = true;
                        }
                    } finally {
                        if (sequence === this.workspaceSearchSequence) {
                            this.workspaceSearchLoading = false;
                            this.workspaceSearchRequest = null;
                        }
                    }
                }, 180);
            },
            restorePaletteFocus() {
                this.$nextTick(() => {
                    const remembered = this.lastPaletteTrigger;
                    const trigger = remembered?.isConnected && remembered.offsetParent !== null
                        ? remembered
                        : [this.$refs.paletteToggle, this.$refs.mobilePaletteToggle, this.$refs.mobileQuickPaletteToggle]
                            .find((element) => element && element.offsetParent !== null);
                    this.lastPaletteTrigger = null;
                    trigger?.focus();
                });
            }
        }"
        @keydown.escape.window="if (palette) { closePalette() } else if (menu) { menu = false; $nextTick(() => $refs.navigationToggle.focus()) }"
        @keydown.window.prevent.cmd.k="openPalette()"
        @keydown.window.prevent.ctrl.k="openPalette()"
    >

        <!--
         ! ------------------------------------------------------------
         ! Load the navigation
         ! ------------------------------------------------------------
         !-->
        <button
            type="button"
            class="app-shell__backdrop fixed inset-0 z-40 lg:hidden"
            style="display: none"
            x-show="menu"
            aria-label="{{ __('Close navigation') }}"
            @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())"
        ></button>
        <x-layouts.sidebar :navigation="$navigation ?? []" />
        <x-layouts.mobile-navigation :navigation="$navigation ?? []" />

        <!--
         ! ------------------------------------------------------------
         ! Website main content
         ! ------------------------------------------------------------
         !-->
        <main id="main-content" tabindex="-1" data-mobile-main class="app-main min-w-0 w-full pl-0 lg:pl-64 min-h-screen">
            <div class="app-topbar sticky top-0 z-30 text-ink" data-mobile-header>
                <div class="app-topbar__mobile flex h-16 items-center justify-between px-4 lg:hidden">
                    <a href="{{ route('dashboard') }}" data-auth-brand class="app-topbar__brand">{{ config('app.name') }}</a>
                    <button type="button" x-ref="mobilePaletteToggle" class="ui-btn ui-btn-secondary hidden min-h-[44px] sm:inline-flex" aria-label="{{ __('Search and navigate') }}" @click="openPalette($event.currentTarget)"><span>{{ __('Search and navigate') }}</span><kbd class="ml-2 rounded-md border border-line px-1.5 py-0.5 text-[10px] text-muted">Ctrl K</kbd></button>
                    <button type="button" class="ui-icon-btn h-11 w-11 shrink-0" data-theme-toggle aria-label="{{ __('Use dark theme') }}" aria-pressed="false"><span data-theme-icon aria-hidden="true">☾</span></button>
                    <button type="button" x-ref="navigationToggle" class="ui-btn ui-btn-secondary flex min-h-[44px] gap-2" aria-controls="primary-navigation" :aria-expanded="menu.toString()" aria-label="{{ __('Toggle navigation') }}" @click="menu = true; $nextTick(() => $refs.closeNavigation.focus())"><svg class="h-4 w-4 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#menu"></use></svg>{{ __('Menu') }}</button>
                </div>
                <div class="app-topbar__desktop hidden h-14 w-full items-center justify-between px-6 lg:flex">
                    <div class="flex items-center gap-3">
                        <div class="hidden sm:block">
                            <button type="button" x-ref="paletteToggle" class="ui-btn ui-btn-secondary" @click="openPalette($event.currentTarget)"><span>{{ __('Search and navigate') }}</span><kbd class="ml-3 rounded-md border border-line px-1.5 py-0.5 text-[10px] text-muted">⌘K</kbd></button>
                        </div>
                    </div>
                    <div class="app-topbar__actions relative flex items-center">
                        <button type="button" class="ui-icon-btn mr-2" data-theme-toggle aria-label="{{ __('Use dark theme') }}" aria-pressed="false"><span data-theme-icon aria-hidden="true">☾</span></button>
                        <a href="{{ route('account.index') }}" aria-label="{{ __('Account settings') }}">
                            <x-avatar :name="auth()->user()->name" class="h-8 w-8 rounded-lg text-[10px] shadow-lg" />
                        </a>

                        <form action="{{ route('logout') }}" method="post" class="ml-4">
                            @csrf
                            <x-ui.button type="submit" variant="ghost">{{ __('Logout') }}</x-ui.button>
                        </form>
                    </div>
                </div>
            </div>

            <div data-mobile-content class="app-main__content mb-0 p-4 sm:mb-20 sm:p-6">
                <x-alerts.flash />
                {{ $slot }}
            </div>
        </main>

        <nav data-mobile-quick-navigation class="ui-bottom-nav lg:hidden" aria-label="{{ __('Mobile quick actions') }}">
            <a href="{{ route('dashboard') }}" data-mobile-quick-action="home" @class(['ui-bottom-nav-link']) @if(request()->routeIs('dashboard')) aria-current="page" @endif><svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#view-grid"></use></svg><span>{{ __('Home') }}</span></a>
            <a href="{{ $applicationCreateDialogUrl }}" data-mobile-quick-action="create" data-modal-trigger="application-create-dialog" aria-controls="application-create-dialog" aria-expanded="{{ $applicationCreateDialogOpen ? 'true' : 'false' }}" @class(['ui-bottom-nav-link']) @if($applicationCreateDialogOpen) aria-current="page" @endif><svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#cloud-upload"></use></svg><span>{{ __('New app') }}</span></a>
            <button type="button" data-mobile-quick-action="search" x-ref="mobileQuickPaletteToggle" class="ui-bottom-nav-link" @click="openPalette($event.currentTarget)"><svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#code"></use></svg><span>{{ __('Search') }}</span></button>
            <a href="{{ route('notifications.index') }}" data-mobile-quick-action="alerts" @class(['ui-bottom-nav-link', 'relative']) @if(request()->routeIs('notifications.*')) aria-current="page" @endif><svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#information-circle"></use></svg><span>{{ __('Alerts') }}</span>@if(($navigation['unread_notifications'] ?? 0) > 0)<span class="ui-status-dot absolute right-3 top-1" style="--ui-status-dot: var(--ui-danger)" aria-label="{{ __('Unread alerts') }}"></span>@endif</a>
        </nav>

        <div
            data-network-status
            hidden
            role="status"
            aria-live="polite"
            class="ui-network-status ui-alert ui-alert--warning"
            data-offline-message="{{ __('You appear to be offline. New changes cannot be sent until your connection returns.') }}"
            data-online-message="{{ __('Connection restored. Refresh if the current page is stale.') }}"
        ></div>

        <div x-cloak x-show="palette" x-trap.inert.noscroll="palette" class="fixed inset-0 z-[70] flex items-start justify-center bg-slate-950/60 px-4 pt-[10vh]" role="dialog" aria-modal="true" aria-labelledby="command-palette-title" data-workspace-search-dialog @click.self="closePalette()">
            <div class="w-full max-w-xl overflow-hidden rounded-2xl border border-line bg-surface shadow-2xl" @keydown.arrow-down.prevent="movePalette(1)" @keydown.arrow-up.prevent="movePalette(-1)" @keydown.home.prevent="movePaletteTo(0)" @keydown.end.prevent="movePaletteTo(paletteLinks().length - 1)">
                <div class="flex items-center justify-between px-4 pt-3"><h2 id="command-palette-title" class="font-bold text-ink">{{ __('Search workspace') }}</h2><x-ui.button type="button" variant="ghost" class="min-h-10 px-2 text-lg" aria-label="{{ __('Close workspace search') }}" @click="closePalette()">×</x-ui.button></div>
                <form method="GET" action="{{ route('search.index') }}" class="border-b border-line p-3">
                    <label for="command-palette-query" class="sr-only">{{ __('Search commands and workspace resources') }}</label>
                    <input id="command-palette-query" x-ref="paletteInput" x-model="paletteQuery" @input="resetPaletteSelection(); queueWorkspaceSearch()" name="q" type="search" maxlength="100" autocomplete="off" class="ui-input w-full rounded-xl text-base" placeholder="{{ __('Search commands and workspace resources…') }}">
                </form>
                <nav x-ref="paletteResults" class="max-h-[55vh] overflow-y-auto p-2" aria-label="{{ __('Quick actions') }}" role="listbox">
                    @foreach ([
                        [__('Dashboard'), route('dashboard'), __('overview home')],
                        [__('Create application'), $applicationCreateDialogUrl, __('new project app')],
                        [__('Provision server'), $serverCreateDialogUrl, __('new cloud infrastructure')],
                        [__('Import existing server'), route('servers.import.create'), __('ssh migrate')],
                        [__('Add website'), $websiteCreateDialogUrl, __('domain site')],
                        [__('Connect repository'), $repositoryCreateDialogUrl, __('git source deploy')],
                        [__('View deployments'), route('builds.index'), __('build history releases')],
                        [__('Open live logs'), route('websites.index'), __('runtime logs')],
                        [__('Observability'), route('observability.index'), __('alerts status incidents')],
                        [__('Database operations'), route('databases.index'), __('mysql postgres clone credentials inspect')],
                        [__('High availability'), route('load-balancers.index'), __('load balancer failover nodes traffic')],
                        [__('API and automation'), route('automation.index'), __('tokens schedules workflow')],
                        [__('Product guide'), route('docs'), __('help documentation')],
                    ] as [$label, $url, $keywords])
                        @php
                            $commandModal = match ($label) {
                                __('Create application') => ['application-create-dialog', null, $applicationCreateDialogOpen],
                                __('Provision server') => ['server-create-dialog', $serverCreateContentUrl, $serverCreateDialogOpen],
                                __('Add website') => ['website-create-dialog', $websiteCreateContentUrl, $websiteCreateDialogOpen],
                                __('Connect repository') => ['repository-create-dialog', $repositoryCreateContentUrl, $repositoryCreateDialogOpen],
                                default => null,
                            };
                        @endphp
                        <a id="command-palette-result-{{ $loop->index }}" href="{{ $url }}" @if($commandModal) data-modal-trigger="{{ $commandModal[0] }}" @if($commandModal[1]) data-modal-content-url="{{ $commandModal[1] }}" @endif aria-controls="{{ $commandModal[0] }}" aria-expanded="{{ $commandModal[2] ? 'true' : 'false' }}" @click="palette = false" @endif x-show="paletteQuery === '' || {{ Illuminate\Support\Js::from(strtolower($label.' '.$keywords)) }}.includes(paletteQuery.toLowerCase())" data-palette-item role="option" :aria-selected="paletteLinks()[paletteIndex] === $el ? 'true' : 'false'" class="flex items-center justify-between rounded-xl px-4 py-3 text-sm font-bold text-ink hover:bg-surface-muted focus:bg-surface-muted focus:outline-hidden">
                            <span>{{ $label }}</span><span aria-hidden="true" class="text-muted">↵</span>
                        </a>
                    @endforeach
                    <div x-show="paletteQuery.trim() !== '' && workspaceSearchLoading" role="status" class="px-4 py-3 text-sm text-muted">{{ __('Searching workspace…') }}</div>
                    <div x-show="paletteQuery.trim() !== '' && workspaceSearchError" role="alert" class="space-y-2 px-4 py-3 text-sm text-muted">
                        <p>{{ __('Workspace search could not be loaded.') }}</p>
                        <button type="button" class="ui-link" @click="queueWorkspaceSearch()">{{ __('Retry') }}</button>
                    </div>
                    <div x-show="workspaceSearchResults !== ''" x-html="workspaceSearchResults"></div>
                    <p x-show="paletteQuery.trim() !== '' && !workspaceSearchLoading && !workspaceSearchError && workspaceSearchResults === '' && paletteLinks().length === 0" role="status" class="px-4 py-3 text-sm text-muted">
                        {{ __('No matching quick actions. Press Enter to search all workspace resources.') }}
                    </p>
                    <div class="border-t border-line px-4 py-3 text-xs text-muted">
                        {{ __('Press Enter to search all workspace resources for your exact query.') }}
                    </div>
                </nav>
            </div>
        </div>

        @if ($applicationCreateDialogHosted)
            <x-scenes.projects.create-dialog
                :templates="$applicationCreationTemplates ?? []"
                :open="$applicationCreateDialogOpen"
                :cancel-url="$applicationCreateDialogCancelUrl"
            />
        @endif

        @if ($providerCreateDialogHosted)
            <x-dialogs.modal
                id="provider-create-dialog"
                :title="__('Add provider')"
                :description="__('Connect an infrastructure or source-control credential to this workspace.')"
                :open="$providerCreateDialogOpen"
                body-class="p-0"
                data-modal-content-loaded="{{ $providerCreateDialogOpen ? 'true' : 'false' }}"
                data-modal-content-url="{{ $providerCreateContentUrl }}"
            >
                <div data-modal-content>
                    @if ($providerCreateDialogOpen)
                        <x-scenes.providers.create-dialog-content :cancel-url="$quickCreateReturnUrl" />
                    @else
                        <p class="p-5 text-sm text-muted">{{ __('Loading provider form…') }}</p>
                    @endif
                </div>
            </x-dialogs.modal>
        @endif

        @if ($serverCreateDialogHosted)
            <x-dialogs.modal
                id="server-create-dialog"
                :title="__('Add server')"
                :description="__('Choose a provider and infrastructure profile, then start server provisioning.')"
                :open="$serverCreateDialogOpen"
                body-class="p-0"
                data-modal-content-loaded="{{ $serverCreateDialogData !== null ? 'true' : 'false' }}"
                data-modal-content-url="{{ $serverCreateContentUrl }}"
            >
                <div data-modal-content>
                    @if ($serverCreateDialogData !== null)
                        <x-scenes.servers.create-dialog-content
                            :types="$serverCreateDialogData['types']"
                            :providers="$serverCreateDialogData['providers']"
                            :sizes="$serverCreateDialogData['sizes']"
                            :images="$serverCreateDialogData['images']"
                            :regions="$serverCreateDialogData['regions']"
                            :recipes="$serverCreateDialogData['recipes']"
                            :plan-usage="$serverCreateDialogData['planUsage']"
                            :cancel-url="$quickCreateReturnUrl"
                            :return-url="$quickCreateReturnUrl"
                        />
                    @else
                        <p class="p-5 text-sm text-muted">{{ __('Loading server form…') }}</p>
                    @endif
                </div>
            </x-dialogs.modal>
        @endif

        @if ($websiteCreateDialogHosted)
            <x-dialogs.modal
                id="website-create-dialog"
                :title="__('Add website')"
                :description="__('Choose a server, configure deployment health checks, and create a new deployment target.')"
                :open="$websiteCreateDialogOpen"
                body-class="p-0"
                data-modal-content-loaded="{{ $websiteCreateDialogData !== null ? 'true' : 'false' }}"
                data-modal-content-url="{{ $websiteCreateContentUrl }}"
            >
                <div data-modal-content>
                    @if ($websiteCreateDialogData !== null)
                        <x-scenes.websites.create-dialog-content
                            :servers="$websiteCreateDialogData['servers']"
                            :plan-usage="$websiteCreateDialogData['planUsage']"
                            :website-index-query="[]"
                            :website-store-url="route('websites.store', ['dialog' => 'create-website'])"
                            :cancel-url="$quickCreateReturnUrl"
                            :return-url="$quickCreateReturnUrl"
                        />
                    @else
                        <p class="p-5 text-sm text-muted">{{ __('Loading website form…') }}</p>
                    @endif
                </div>
            </x-dialogs.modal>
        @endif

        @if ($repositoryCreateDialogHosted)
            <x-dialogs.modal
                id="repository-create-dialog"
                :title="__('Add repository')"
                :description="__('Connect a source repository to an active website and deployment branch.')"
                :open="$repositoryCreateDialogOpen"
                body-class="p-0"
                data-modal-content-loaded="{{ $repositoryCreateDialogData !== null ? 'true' : 'false' }}"
                data-modal-content-url="{{ $repositoryCreateContentUrl }}"
            >
                <div data-modal-content>
                    @if ($repositoryCreateDialogData !== null)
                        <x-scenes.repositories.create-dialog-content
                            :providers="$repositoryCreateDialogData['providers']"
                            :websites="$repositoryCreateDialogData['websites']"
                            :index-query="[]"
                            :cancel-url="$quickCreateReturnUrl"
                            :return-url="$quickCreateReturnUrl"
                        />
                    @else
                        <p class="p-5 text-sm text-muted">{{ __('Loading repository form…') }}</p>
                    @endif
                </div>
            </x-dialogs.modal>
        @endif

        <!--
         ! ------------------------------------------------------------
         ! Footer and links
         ! ------------------------------------------------------------
         !-->
        <div data-mobile-footer class="app-footer hidden w-full items-center justify-between px-6 py-6 text-sm sm:px-8 lg:flex">
            <p class="mb-2 lg:mb-0">
                &copy; {{ now()->year }} {{ config('app.name') }}
            </p>
            <nav class="flex" aria-label="{{ __('Footer navigation') }}">
                <a href="{{ route('dashboard') }}" class="app-footer__link mr-6">{{ __('Dashboard') }}</a>
                <a href="{{ route('activity.index') }}" class="app-footer__link mr-6">{{ __('Activity') }}</a>
                <a href="{{ route('account.index') }}" class="app-footer__link">{{ __('Account') }}</a>
            </nav>
        </div>
    </div>
</x-layouts.core>
