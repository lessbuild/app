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
    $deployerCommandItems = [
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'keywords' => __('overview home')],
        ['label' => __('Create application'), 'href' => $applicationCreateDialogUrl, 'keywords' => __('new project app'), 'modal' => 'application-create-dialog', 'modalOpen' => $applicationCreateDialogOpen],
        ['label' => __('Provision server'), 'href' => $serverCreateDialogUrl, 'keywords' => __('new cloud infrastructure'), 'modal' => 'server-create-dialog', 'modalUrl' => $serverCreateContentUrl, 'modalOpen' => $serverCreateDialogOpen],
        ['label' => __('Import existing server'), 'href' => route('servers.import.create'), 'keywords' => __('ssh migrate')],
        ['label' => __('Add website'), 'href' => $websiteCreateDialogUrl, 'keywords' => __('domain site'), 'modal' => 'website-create-dialog', 'modalUrl' => $websiteCreateContentUrl, 'modalOpen' => $websiteCreateDialogOpen],
        ['label' => __('Connect repository'), 'href' => $repositoryCreateDialogUrl, 'keywords' => __('git source deploy'), 'modal' => 'repository-create-dialog', 'modalUrl' => $repositoryCreateContentUrl, 'modalOpen' => $repositoryCreateDialogOpen],
        ['label' => __('View deployments'), 'href' => route('builds.index'), 'keywords' => __('build history releases')],
        ['label' => __('Open live logs'), 'href' => route('websites.index'), 'keywords' => __('runtime logs')],
        ['label' => __('Observability'), 'href' => route('observability.index'), 'keywords' => __('alerts status incidents')],
        ['label' => __('Database operations'), 'href' => route('databases.index'), 'keywords' => __('mysql postgres clone credentials inspect')],
        ['label' => __('High availability'), 'href' => route('load-balancers.index'), 'keywords' => __('load balancer failover nodes traffic')],
        ['label' => __('API and automation'), 'href' => route('automation.index'), 'keywords' => __('tokens schedules workflow')],
        ['label' => __('Product guide'), 'href' => route('docs'), 'keywords' => __('help documentation')],
    ];
@endphp

<x-signal.layouts.core :title="$resolvedTitle" :description="$description" product-key="deployer">
    <a href="#main-content" class="ui-skip-link">
        {{ __('Skip to main content') }}
    </a>
    <div data-mobile-shell class="min-h-screen overflow-x-hidden">

        <x-signal.layouts.topbar
            :navigation="$navigation ?? []"
            :title="$resolvedTitle"
            product-key="deployer"
            :product-url-overrides="$productUrlOverrides ?? null"
            :shared-context-unavailable="$sharedContextUnavailable ?? false"
        />

        <main id="main-content" tabindex="-1" data-mobile-main data-mobile-content class="ui-layout-gutter mx-auto w-full max-w-screen-2xl py-7 sm:py-9">
            <x-alerts.flash />
            {{ $slot }}
        </main>
        <x-layouts.mobile-quick-navigation
            :create-url="$applicationCreateDialogUrl"
            :create-open="$applicationCreateDialogOpen"
        />
        <x-signal.layouts.command-palette
            :navigation="$navigation ?? []"
            :search-url="route('search.index')"
            :search-action-url="route('search.index')"
            :extra-items="$deployerCommandItems"
        />

        <div
            data-network-status
            hidden
            role="status"
            aria-live="polite"
            class="ui-network-status ui-alert ui-alert--warning"
            data-offline-message="{{ __('You appear to be offline. New changes cannot be sent until your connection returns.') }}"
            data-online-message="{{ __('Connection restored. Refresh if the current page is stale.') }}"
        ></div>

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
