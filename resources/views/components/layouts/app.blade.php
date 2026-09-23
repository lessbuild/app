@props([
    'title' => null,
    'description' => null,
])

@php
    $resolvedTitle = $title ?: app(\App\Modules\Deployer\View\PageTitle::class)->for(request()->route());
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

<x-signal.layouts.core :title="$resolvedTitle" :description="$description">
    <a href="#main-content" class="ui-skip-link">
        {{ __('Skip to main content') }}
    </a>
    <div
        data-mobile-shell
        class="min-h-screen overflow-x-hidden"
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
                this.$nextTick(() => {
                    const dialog = this.$refs.commandPalette;

                    if (dialog && ! dialog.open) {
                        dialog.showModal();
                    }

                    this.$refs.paletteInput.focus();
                });
            },
            closePalette() {
                this.palette = false;
                this.workspaceSearchRequest?.abort();
                this.workspaceSearchSequence += 1;

                if (this.$refs.commandPalette?.open) {
                    this.$refs.commandPalette.close();
                }

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

        <x-signal.layouts.topbar :navigation="$navigation ?? []" :title="$resolvedTitle" />

        <main id="main-content" tabindex="-1" data-mobile-main data-mobile-content class="mx-auto w-full max-w-screen-2xl px-4 py-7 sm:px-6 sm:py-9 lg:px-8">
            <x-alerts.flash />
            {{ $slot }}
        </main>
        <x-layouts.mobile-navigation :navigation="$navigation ?? []" />

        <div
            data-network-status
            hidden
            role="status"
            aria-live="polite"
            class="ui-network-status ui-alert ui-alert--warning"
            data-offline-message="{{ __('You appear to be offline. New changes cannot be sent until your connection returns.') }}"
            data-online-message="{{ __('Connection restored. Refresh if the current page is stale.') }}"
        ></div>

        <dialog id="command-palette" x-ref="commandPalette" class="ui-dialog ui-command-dialog max-h-[80vh] overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="command-palette-title" data-workspace-search-dialog @cancel.prevent="closePalette()" @keydown.arrow-down.prevent="movePalette(1)" @keydown.arrow-up.prevent="movePalette(-1)" @keydown.home.prevent="movePaletteTo(0)" @keydown.end.prevent="movePaletteTo(paletteLinks().length - 1)">
            <div class="p-5 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="ui-eyebrow">{{ __('Quick navigation') }}</p>
                        <h2 id="command-palette-title" class="mt-2 text-xl font-extrabold text-ink">{{ __('Search workspace') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-muted">{{ __('Search pages, resources and workspace actions without leaving the keyboard.') }}</p>
                    </div>
                    <button type="button" class="ui-icon-btn" aria-label="{{ __('Close workspace search') }}" @click="closePalette()">
                        <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#close"></use></svg>
                    </button>
                </div>
                <form method="GET" action="{{ route('search.index') }}" class="mt-6">
                    <label for="command-palette-query" class="sr-only">{{ __('Search commands and workspace resources') }}</label>
                    <x-ui.input
                        id="command-palette-query"
                        x-ref="paletteInput"
                        x-model="paletteQuery"
                        @input="resetPaletteSelection(); queueWorkspaceSearch()"
                        name="q"
                        type="search"
                        maxlength="100"
                        autocomplete="off"
                        class="text-base"
                        placeholder="{{ __('Search commands and workspace resources…') }}"
                    />
                </form>
                <nav x-ref="paletteResults" class="mt-4 grid max-h-[min(28rem,55vh)] gap-1 overflow-y-auto" aria-label="{{ __('Quick actions') }}" role="listbox">
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
                        <a id="command-palette-result-{{ $loop->index }}" href="{{ $url }}" @if($commandModal) data-modal-trigger="{{ $commandModal[0] }}" @if($commandModal[1]) data-modal-content-url="{{ $commandModal[1] }}" @endif aria-controls="{{ $commandModal[0] }}" aria-expanded="{{ $commandModal[2] ? 'true' : 'false' }}" @click="closePalette()" @endif x-show="paletteQuery === '' || {{ Illuminate\Support\Js::from(strtolower($label.' '.$keywords)) }}.includes(paletteQuery.toLowerCase())" data-palette-item role="option" :aria-selected="paletteLinks()[paletteIndex] === $el ? 'true' : 'false'" class="ui-command-item flex items-center justify-between gap-3 rounded-card px-3 py-3 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink focus:bg-surface-muted focus:text-ink focus:outline-hidden">
                            <span>{{ $label }}</span><span aria-hidden="true" class="text-muted">↵</span>
                        </a>
                    @endforeach
                    <div x-show="paletteQuery.trim() !== '' && workspaceSearchLoading" role="status" class="px-3 py-3 text-sm text-muted">{{ __('Searching workspace…') }}</div>
                    <div x-show="paletteQuery.trim() !== '' && workspaceSearchError" role="alert" class="space-y-2 px-3 py-3 text-sm text-muted">
                        <p>{{ __('Workspace search could not be loaded.') }}</p>
                        <button type="button" class="ui-link" @click="queueWorkspaceSearch()">{{ __('Retry') }}</button>
                    </div>
                    <div x-show="workspaceSearchResults !== ''" x-html="workspaceSearchResults"></div>
                    <p x-show="paletteQuery.trim() !== '' && !workspaceSearchLoading && !workspaceSearchError && workspaceSearchResults === '' && paletteLinks().length === 0" role="status" class="px-3 py-3 text-sm text-muted">
                        {{ __('No matching quick actions. Press Enter to search all workspace resources.') }}
                    </p>
                    <p class="mt-3 border-t border-line pt-3 text-[11px] text-subtle">{{ __('Press Enter to search all workspace resources for your exact query.') }}</p>
                </nav>
            </div>
        </dialog>

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

    </div>
</x-signal.layouts.core>
