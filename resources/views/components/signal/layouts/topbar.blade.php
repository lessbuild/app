@props([
    'navigation' => [],
    'title' => null,
    'productKey' => null,
    'brandUrl' => null,
    'projectsUrl' => null,
    'currentWorkspace' => null,
    'workspaceOptions' => null,
    'workspaceSwitchRoute' => 'organizations.switch',
    'workspaceManageRoute' => 'organizations.index',
    'workspaceManageParameters' => [],
    'accountUser' => null,
    'logoutRoute' => 'logout',
    'notificationsUrl' => null,
    'showNotifications' => true,
    'currentContext' => null,
    'contextOptions' => null,
    'contextShowRoute' => 'projects.show',
    'currentEnvironment' => null,
    'contextLabel' => 'Project',
    'contextIndexLabel' => 'All projects',
    'contextAllLabel' => 'View all projects',
    'contextIndexUrl' => null,
    'environmentOptions' => null,
    'environmentIndexUrl' => null,
    'environmentContextUnavailable' => false,
    'productUrlOverrides' => null,
    'sharedContextUnavailable' => false,
    'showProjectContext' => true,
    'showEnvironmentContext' => true,
])

@php
    $user = $accountUser ?? auth()->user();
    $currentWorkspace = $currentWorkspace ?? $user?->currentOrganization;
    $workspaceOptions = $workspaceOptions ?? ($navigation['workspaces'] ?? []);
    $currentProject = request()->route('project');
    $currentEnvironment = $currentEnvironment ?? request()->route('environment');

    if (! $currentProject && $currentEnvironment instanceof \App\Modules\Deployer\Models\Environment) {
        $currentProject = $currentEnvironment->project;
    }

    $currentProject = $currentContext ?? $currentProject;
    $currentProjectId = data_get($currentProject, 'id');
    $contextOptions = $contextOptions ?? ($navigation['projects'] ?? []);
    $environmentOptions = $environmentOptions ?? data_get($currentProject, 'environments', []);
    $products = config('platform.products', []);
    $currentHost = request()->getHost();
    $platformSsoUser = \Illuminate\Support\Facades\Auth::guard('platform')->user();
    $platformSsoIssueUrl = url('/__platform/sso/issue');
    $platformSsoHref = static function ($href) use ($platformSsoUser, $platformSsoIssueUrl): ?string {
        if (! is_string($href) || ! $platformSsoUser instanceof \App\Core\Models\PlatformUser) {
            return $href;
        }

        $target = parse_url($href);
        if (! is_array($target) || ! isset($target['host'])) {
            return $href;
        }

        $scheme = strtolower((string) ($target['scheme'] ?? request()->getScheme()));
        $origin = $scheme.'://'.strtolower((string) $target['host']);
        $targetPort = isset($target['port']) ? (int) $target['port'] : null;
        if ($targetPort !== null && !(($scheme === 'https' && $targetPort === 443) || ($scheme === 'http' && $targetPort === 80))) {
            $origin .= ':'.$targetPort;
        }

        if ($origin === strtolower(request()->getSchemeAndHttpHost())) {
            return $href;
        }

        return $platformSsoIssueUrl.'?'.http_build_query(['return_to' => $href], '', '&', PHP_QUERY_RFC3986);
    };
    $activeProduct = $productKey ?? collect($products)->keys()->first(fn (string $key): bool =>
        request()->routeIs($key.'.*')
        || (filled($products[$key]['host'] ?? null) && strcasecmp((string) $products[$key]['host'], $currentHost) === 0)
    ) ?? 'deployer';
    $activeProductLabel = $products[$activeProduct]['label'] ?? ($activeProduct === 'core' ? __('Workspace') : __('Deployer'));
    $brandUrl ??= \Illuminate\Support\Facades\Route::has($activeProduct.'.dashboard')
        ? route($activeProduct.'.dashboard')
        : (\Illuminate\Support\Facades\Route::has('dashboard') ? route('dashboard') : url('/'));
    $projectsUrl ??= $navigation['projects_url'] ?? (\Illuminate\Support\Facades\Route::has('projects.index') ? route('projects.index') : url('/projects'));
    $contextIndexUrl ??= $projectsUrl;
    $environmentIndexUrl ??= ($currentProject && \Illuminate\Support\Facades\Route::has('projects.show'))
        ? route('projects.show', $currentProject).'#environment-'.data_get($environmentOptions, '0.id').'-heading'
        : $contextIndexUrl;
    $workspaceManageUrl = \Illuminate\Support\Facades\Route::has($workspaceManageRoute)
        ? route($workspaceManageRoute, $workspaceManageParameters)
        : null;
    $notificationsUrl ??= $navigation['notifications_url'] ?? (\Illuminate\Support\Facades\Route::has('notifications.index') ? route('notifications.index') : null);
    $logoutUrl = \Illuminate\Support\Facades\Route::has($logoutRoute) ? route($logoutRoute) : null;
@endphp

<header class="sticky top-0 z-40 border-b border-line bg-surface/95 shadow-soft backdrop-blur" data-mobile-header data-topbar-shell>
    <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8">
        <div class="flex min-h-16 items-center gap-3">
            @if (in_array($activeProduct, ['core', 'deployer', 'monitor', 'analytics'], true))
                <button
                    type="button"
                    class="ui-icon-btn shrink-0 lg:hidden"
                    data-mobile-toggle
                    aria-label="{{ __('Open application navigation') }}"
                    aria-controls="signal-mobile-product-navigation-drawer"
                    aria-expanded="false"
                >
                    <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#menu"></use></svg>
                </button>
            @else
                <button type="button" x-ref="navigationToggle" class="ui-icon-btn shrink-0 lg:hidden" aria-label="{{ __('Open navigation') }}" aria-controls="app-mobile-nav" :aria-expanded="menu.toString()" @click="menu = true; $nextTick(() => $refs.closeNavigation.focus())">
                    <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#menu"></use></svg>
                </button>
            @endif

            <a href="{{ $brandUrl }}" data-auth-brand class="flex min-w-0 shrink-0 items-center gap-2.5 text-sm font-extrabold tracking-tight text-ink" aria-label="{{ config('app.name') }} home">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-ink text-surface shadow-soft">
                    <img src="{{ asset('favicon.svg') }}" alt="" class="h-5 w-5 rounded-md">
                </span>
                <span class="hidden truncate sm:inline">{{ config('app.name') }}</span>
            </a>

            <nav class="hidden min-w-0 flex-1 items-center gap-1 overflow-x-auto pl-2 lg:flex" aria-label="{{ __('Products') }}">
                @if ($activeProduct === 'core' && $currentWorkspace && \Illuminate\Support\Facades\Route::has('core.workspace.dashboard'))
                    <x-signal.layouts.navigation-link :item="['label' => __('Overview'), 'href' => route('core.workspace.dashboard', $currentWorkspace), 'active' => request()->routeIs('core.workspace.dashboard')]" class="topbar-nav-link" />
                @endif
                <x-signal.layouts.navigation-link :item="['label' => __('Projects'), 'href' => $projectsUrl, 'active' => request()->routeIs('projects.*', 'core.projects.*')]" class="topbar-nav-link" />
                @foreach (['deployer' => ['label' => __('Deployer'), 'route' => 'dashboard', 'active' => ['dashboard', 'projects.show', 'projects.create', 'projects.configuration.*', 'servers.*', 'websites.*', 'builds.*', 'providers.*', 'repositories.*', 'environments.*']], 'monitor' => ['label' => __('Monitor'), 'route' => 'monitor.dashboard'], 'analytics' => ['label' => __('Analytics'), 'route' => 'analytics.dashboard']] as $key => $product)
                    @php
                        $label = $product['label'];
                        $productRoute = $product['route'];
                        $productHref = is_array($productUrlOverrides) && array_key_exists($key, $productUrlOverrides)
                            ? $productUrlOverrides[$key]
                            : (\Illuminate\Support\Facades\Route::has($productRoute)
                                ? route($productRoute)
                                : (($products[$key]['enabled'] ?? false) ? ($products[$key]['url'] ?? null) : null));
                        $productHref = $platformSsoHref($productHref);
                        $productActive = $activeProduct === $key;
                    @endphp
                    @if ($productHref)
                        <x-signal.layouts.navigation-link :item="['label' => $label, 'href' => $productHref, 'active' => $productActive]" class="topbar-nav-link" />
                    @else
                        <span class="inline-flex min-h-10 items-center gap-2 rounded-control px-3 text-sm font-bold text-subtle" aria-disabled="true" title="{{ __('This product is being connected.') }}">
                            {{ $label }}
                            <span class="rounded-full border border-line px-1.5 py-0.5 text-[9px] font-extrabold uppercase tracking-wide">{{ __('Soon') }}</span>
                        </span>
                    @endif
                @endforeach
            </nav>

            <div class="ml-auto flex shrink-0 items-center gap-1.5 sm:gap-2">
                <details data-signal-menu class="ui-topbar-menu group relative hidden max-w-52 md:block" @click.outside="$el.open = false" @keydown.escape.stop="$el.open = false; $el.querySelector('summary')?.focus()">
                    <summary class="inline-flex min-h-10 max-w-52 cursor-pointer list-none items-center gap-2 rounded-control border border-line bg-surface px-3 text-sm font-bold text-ink marker:hidden hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden">
                        <svg class="h-4 w-4 shrink-0 stroke-2 text-primary" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#user-circle"></use></svg>
                        <span class="max-w-32 truncate">{{ $currentWorkspace?->name ?? __('Workspace') }}</span>
                        <svg class="h-3.5 w-3.5 shrink-0 rotate-90 stroke-2 text-muted transition-transform group-open:-rotate-90" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                    </summary>
                    <div class="absolute right-0 top-full z-40 mt-2 grid min-w-64 gap-1 rounded-panel border border-line bg-surface p-2 shadow-panel">
                        <p class="px-3 py-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Switch workspace') }}</p>
                        @foreach ($workspaceOptions as $workspace)
                            <form method="POST" action="{{ route($workspaceSwitchRoute, $workspace) }}">
                                @csrf
                                <button type="submit" @class([
                                    'flex min-h-10 w-full items-center gap-2 rounded-control px-3 text-left text-sm font-bold',
                                    'bg-primary-soft text-primary' => $currentWorkspace?->id === $workspace->id,
                                    'text-muted hover:bg-surface-muted hover:text-ink' => $currentWorkspace?->id !== $workspace->id,
                                ]) @if($currentWorkspace?->id === $workspace->id) aria-current="true" @endif>
                                    <span class="min-w-0 flex-1 truncate">{{ $workspace->name }}</span>
                                    @if ($currentWorkspace?->id === $workspace->id)<span aria-hidden="true">✓</span>@endif
                                </button>
                            </form>
                        @endforeach
                        @if ($workspaceManageUrl)<a href="{{ $workspaceManageUrl }}" class="mt-1 rounded-control border-t border-line px-3 py-3 text-sm font-bold text-primary hover:bg-surface-muted">{{ __('Manage workspace') }}</a>@endif
                    </div>
                </details>

                @if (in_array($activeProduct, ['core', 'deployer', 'monitor', 'analytics'], true))
                    <x-signal.ui.button type="button" class="ui-btn-sm hidden sm:inline-flex" aria-label="{{ __('Jump to') }}" aria-controls="signal-command-palette" aria-haspopup="dialog" data-signal-command-open>
                        <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#command"></use></svg>
                        <span class="hidden xl:inline">{{ __('Search') }}</span>
                        <kbd class="ui-kbd hidden xl:inline-flex">⌘K</kbd>
                    </x-signal.ui.button>
                    <x-signal.ui.icon-button label="{{ __('Open quick navigation') }}" class="sm:hidden" aria-controls="signal-command-palette" aria-haspopup="dialog" data-signal-command-open>
                        <svg class="h-[18px] w-[18px] stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#command"></use></svg>
                    </x-signal.ui.icon-button>
                @else
                    <x-signal.ui.button type="button" x-ref="paletteToggle" class="ui-btn-sm hidden sm:inline-flex" aria-label="{{ __('Jump to') }}" aria-controls="command-palette" aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K" @click="openPalette($event.currentTarget)">
                        <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#command"></use></svg>
                        <span class="hidden xl:inline">{{ __('Search') }}</span>
                        <kbd class="ui-kbd hidden xl:inline-flex">⌘K</kbd>
                    </x-signal.ui.button>
                    <x-signal.ui.icon-button label="{{ __('Open quick navigation') }}" x-ref="mobilePaletteToggle" class="sm:hidden" aria-controls="command-palette" aria-haspopup="dialog" @click="openPalette($event.currentTarget)">
                        <svg class="h-[18px] w-[18px] stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#command"></use></svg>
                    </x-signal.ui.icon-button>
                @endif
                @if ($showNotifications && $notificationsUrl)
                    <x-signal.ui.icon-button label="{{ __('Notifications') }}" href="{{ $notificationsUrl }}" class="relative" :aria-current="request()->routeIs('notifications.*', 'monitor.settings.notifications') ? 'page' : null">
                        <svg class="h-[18px] w-[18px] stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#information-circle"></use></svg>
                        @if (($navigation['unread_notifications'] ?? 0) > 0)
                            <span class="ui-status-dot absolute right-2 top-2" style="--ui-status-dot: var(--ui-danger)" aria-label="{{ __('Unread notifications') }}"></span>
                        @endif
                    </x-signal.ui.icon-button>
                @endif
                <x-signal.ui.icon-button label="{{ __('Use dark theme') }}" data-theme-toggle aria-pressed="false">
                    <svg class="h-[19px] w-[19px] dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.2 15.1A8.5 8.5 0 0 1 8.9 3.8 8.6 8.6 0 1 0 20.2 15.1Z" /></svg>
                    <svg class="hidden h-[19px] w-[19px] dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="3.6" /><path stroke-linecap="round" d="M12 2.5v2M12 19.5v2M4.3 4.3l1.4 1.4m12.6 12.6 1.4 1.4M2.5 12h2m15 0h2M4.3 19.7l1.4-1.4M18.3 5.7l1.4-1.4" /></svg>
                </x-signal.ui.icon-button>
                <details data-signal-menu class="ui-topbar-menu group relative" @click.outside="$el.open = false" @keydown.escape.stop="$el.open = false; $el.querySelector('summary')?.focus()">
                    <summary class="flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-control px-1.5 text-sm font-bold text-ink marker:hidden hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden" aria-label="{{ __('Account menu for :name', ['name' => $user?->name]) }}">
                        <x-signal.ui.avatar :name="$user?->name ?? 'User'" class="ui-avatar ui-avatar-sm" />
                        <svg class="hidden h-3.5 w-3.5 rotate-90 stroke-2 text-muted transition-transform group-open:-rotate-90 sm:block" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                    </summary>
                    <div class="absolute right-0 top-full z-40 mt-2 grid min-w-56 gap-1 rounded-panel border border-line bg-surface p-2 shadow-panel">
                        <div class="border-b border-line px-3 pb-3 pt-2">
                            <p class="truncate text-sm font-extrabold text-ink">{{ $user?->name }}</p>
                            <p class="truncate text-xs text-muted">{{ $user?->email }}</p>
                        </div>
                        <nav id="signal-profile-navigation" class="grid gap-1" aria-label="{{ __('Account and workspace') }}">
                            @foreach ($navigation['profile'] ?? [] as $item)
                                <x-signal.layouts.navigation-link :item="$item" class="w-full justify-start" />
                            @endforeach
                        </nav>
                        @foreach ($navigation['support'] ?? [] as $item)
                            <x-signal.layouts.navigation-link :item="$item" class="w-full justify-start" />
                        @endforeach
                        @if ($logoutUrl)
                            <form action="{{ $logoutUrl }}" method="post" class="mt-1 border-t border-line pt-1">
                                @csrf
                                <button type="submit" class="flex min-h-10 w-full items-center rounded-control px-3 text-left text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ __('Log out') }}</button>
                            </form>
                        @endif
                    </div>
                </details>
            </div>
        </div>

        <div class="flex min-h-14 flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-line py-2">
            <div class="flex min-w-0 flex-wrap items-center gap-2" aria-label="{{ $contextLabel }} and environment context">
            @if ($sharedContextUnavailable)
                <x-signal.ui.alert tone="warning" class="min-h-9 items-center rounded-control px-3 py-2 text-xs font-bold" role="status">
                    <svg class="h-4 w-4 shrink-0 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#information-circle"></use></svg>
                    {{ __('Shared project context could not be verified') }}
                </x-signal.ui.alert>
            @endif
            @if ($showProjectContext)
            <details data-signal-menu class="ui-topbar-menu group relative shrink-0">
                <summary class="inline-flex min-h-9 cursor-pointer list-none items-center gap-2 rounded-control border border-line bg-surface px-3 text-left marker:hidden hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden">
                    <span class="text-[10px] font-extrabold uppercase tracking-wide text-subtle">{{ $contextLabel }}</span>
                    <span class="max-w-36 truncate text-xs font-extrabold text-ink">{{ data_get($currentProject, 'name') ?? $contextIndexLabel }}</span>
                    <svg class="h-3.5 w-3.5 rotate-90 stroke-2 text-muted transition-transform group-open:-rotate-90" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                </summary>
                <div class="absolute left-0 top-full z-40 mt-2 grid max-h-80 min-w-64 gap-1 overflow-y-auto rounded-panel border border-line bg-surface p-2 shadow-panel">
                    <a href="{{ $contextIndexUrl }}" @class(['rounded-control px-3 py-2 text-sm font-bold', 'bg-primary-soft text-primary' => ! $currentProjectId, 'text-muted hover:bg-surface-muted hover:text-ink' => $currentProjectId]) @if(! $currentProjectId) aria-current="page" @endif>{{ $contextIndexLabel }}</a>
                    @foreach ($contextOptions as $contextOption)
                        @php
                            $contextOptionId = data_get($contextOption, 'id');
                            $contextOptionHref = data_get($contextOption, 'href');
                            if (! $contextOptionHref && \Illuminate\Support\Facades\Route::has($contextShowRoute)) $contextOptionHref = route($contextShowRoute, $contextOption);
                            $contextOptionSelected = (string) $currentProjectId === (string) $contextOptionId;
                        @endphp
                        @if ($contextOptionHref)
                            <a href="{{ $contextOptionHref }}" @class(['rounded-control px-3 py-2 text-sm font-bold', 'bg-primary-soft text-primary' => $contextOptionSelected, 'text-muted hover:bg-surface-muted hover:text-ink' => ! $contextOptionSelected]) @if($contextOptionSelected) aria-current="page" @endif>{{ data_get($contextOption, 'name') }}</a>
                        @endif
                    @endforeach
                    <a href="{{ $contextIndexUrl }}" class="mt-1 border-t border-line px-3 py-2 text-sm font-bold text-primary">{{ $contextAllLabel }}</a>
                </div>
            </details>
            @endif

            @if ($showEnvironmentContext)
            <details data-signal-menu class="ui-topbar-menu group relative shrink-0">
                <summary class="inline-flex min-h-9 cursor-pointer list-none items-center gap-2 rounded-control border border-line bg-surface px-3 text-left marker:hidden hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden">
                    <span class="text-[10px] font-extrabold uppercase tracking-wide text-subtle">{{ __('Environment') }}</span>
                    <span class="max-w-36 truncate text-xs font-extrabold text-ink">{{ data_get($currentEnvironment, 'name') ?? ($environmentContextUnavailable ? __('Unavailable') : __('All environments')) }}</span>
                    <svg class="h-3.5 w-3.5 rotate-90 stroke-2 text-muted transition-transform group-open:-rotate-90" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                </summary>
                @if (count($environmentOptions))
                    <div class="absolute left-0 top-full z-40 mt-2 grid max-h-80 min-w-64 gap-1 overflow-y-auto rounded-panel border border-line bg-surface p-2 shadow-panel">
                        <a href="{{ $environmentIndexUrl }}" class="rounded-control px-3 py-2 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ __('All environments') }}</a>
                        @foreach ($environmentOptions as $environmentOption)
                            @php
                                $environmentOptionHref = data_get($environmentOption, 'href');
                                if (! $environmentOptionHref && $currentProject && \Illuminate\Support\Facades\Route::has('projects.show')) {
                                    $environmentOptionHref = route('projects.show', $currentProject).'#environment-'.data_get($environmentOption, 'id').'-heading';
                                }
                            @endphp
                            @if ($environmentOptionHref)
                                <a href="{{ $environmentOptionHref }}" @class(['rounded-control px-3 py-2 text-sm font-bold', 'bg-primary-soft text-primary' => (string) data_get($currentEnvironment, 'id') === (string) data_get($environmentOption, 'id'), 'text-muted hover:bg-surface-muted hover:text-ink' => (string) data_get($currentEnvironment, 'id') !== (string) data_get($environmentOption, 'id')]) @if((string) data_get($currentEnvironment, 'id') === (string) data_get($environmentOption, 'id')) aria-current="page" @endif>{{ data_get($environmentOption, 'name') }}</a>
                            @endif
                        @endforeach
                    </div>
                @else
                    <div class="absolute left-0 top-full z-40 mt-2 max-w-64 rounded-panel border border-line bg-surface p-3 text-xs leading-5 text-muted shadow-panel">{{ __('Choose a :context to view its environments.', ['context' => strtolower($contextLabel)]) }}</div>
                @endif
            </details>
            @endif
            </div>
            <nav id="signal-product-navigation" class="hidden min-w-0 flex-1 flex-wrap items-center justify-end gap-1 overflow-x-auto lg:flex" aria-label="{{ __(':product sections', ['product' => $activeProductLabel]) }}">
                @foreach ($navigation['groups'] ?? [] as $group)
                    <x-signal.layouts.navigation-group :group="$group" />
                @endforeach
            </nav>
        </div>
    </div>
</header>

@if (in_array($activeProduct, ['core', 'deployer', 'monitor', 'analytics'], true))
    <x-signal.layouts.mobile-navigation
        id="signal-mobile-product-navigation-drawer"
        :active-product="$activeProduct"
        :active-product-label="$activeProductLabel"
        :brand-url="$brandUrl"
        :platform-sso-href="$platformSsoHref"
        :products="$products"
        :projects-url="$projectsUrl"
        :product-url-overrides="$productUrlOverrides"
        :current-workspace="$currentWorkspace"
        :workspace-options="$workspaceOptions"
        :workspace-switch-route="$workspaceSwitchRoute"
        :workspace-manage-url="$workspaceManageUrl"
        :show-project-context="$showProjectContext"
        :context-label="$contextLabel"
        :context-index-label="$contextIndexLabel"
        :context-index-url="$contextIndexUrl"
        :context-options="$contextOptions"
        :show-environment-context="$showEnvironmentContext"
        :environment-options="$environmentOptions"
        :environment-context-unavailable="$environmentContextUnavailable"
        :environment-index-url="$environmentIndexUrl"
        :navigation="$navigation"
        :logout-url="$logoutUrl"
    />
@endif
