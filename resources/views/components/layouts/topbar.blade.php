@props([
    'navigation' => [],
    'title' => null,
])

@php
    $user = auth()->user();
    $currentWorkspace = $user?->currentOrganization;
    $currentProject = request()->route('project');
    $currentEnvironment = request()->route('environment');

    if (! $currentProject && $currentEnvironment instanceof \App\Modules\Deployer\Models\Environment) {
        $currentProject = $currentEnvironment->project;
    }

    $currentProjectId = data_get($currentProject, 'id');
    $products = config('platform.products', []);
    $currentHost = request()->getHost();
    $activeProduct = collect($products)->keys()->first(fn (string $key): bool =>
        request()->routeIs($key.'.*')
        || (filled($products[$key]['host'] ?? null) && strcasecmp((string) $products[$key]['host'], $currentHost) === 0)
    ) ?? 'deployer';
    $activeProductLabel = $products[$activeProduct]['label'] ?? __('Deployer');
@endphp

<header class="sticky top-0 z-40 border-b border-line bg-surface/95 shadow-soft backdrop-blur" data-mobile-header data-topbar-shell>
    <div class="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8">
        <div class="flex min-h-16 items-center gap-3">
            <button type="button" x-ref="navigationToggle" class="ui-icon-btn shrink-0 lg:hidden" aria-label="{{ __('Open navigation') }}" aria-controls="app-mobile-nav" :aria-expanded="menu.toString()" @click="menu = true; $nextTick(() => $refs.closeNavigation.focus())">
                <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#menu"></use></svg>
            </button>

            <a href="{{ route('dashboard') }}" data-auth-brand class="flex min-w-0 shrink-0 items-center gap-2.5 text-sm font-extrabold tracking-tight text-ink" aria-label="{{ config('app.name') }} home">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-ink text-surface shadow-soft">
                    <img src="{{ asset('favicon.svg') }}" alt="" class="h-5 w-5 rounded-md">
                </span>
                <span class="hidden truncate sm:inline">{{ config('app.name') }}</span>
            </a>

            <nav class="hidden min-w-0 flex-1 items-center gap-1 overflow-x-auto pl-2 lg:flex" aria-label="{{ __('Products') }}">
                <x-layouts.topbar-navigation-link :item="['label' => __('Projects'), 'route' => 'projects.index', 'active' => ['projects.*']]" />
                @foreach (['deployer' => ['label' => __('Deployer'), 'route' => 'dashboard', 'active' => ['dashboard', 'projects.show', 'projects.create', 'projects.configuration.*', 'servers.*', 'websites.*', 'builds.*', 'providers.*', 'repositories.*', 'environments.*']], 'monitor' => ['label' => __('Monitor')], 'analytics' => ['label' => __('Analytics')]] as $key => $product)
                    @php($label = $product['label'])
                    @if ($key === 'deployer')
                        <x-layouts.topbar-navigation-link :item="['label' => $label, 'route' => $product['route'], 'active' => $product['active']]" />
                    @elseif (($products[$key]['enabled'] ?? false) && filled($products[$key]['url'] ?? null))
                        <a href="{{ $products[$key]['url'] }}" @class([
                            'inline-flex min-h-10 items-center rounded-control px-3 text-sm font-bold transition-colors',
                            'bg-primary-soft text-primary' => $activeProduct === $key,
                            'text-muted hover:bg-surface-muted hover:text-ink' => $activeProduct !== $key,
                        ]) @if($activeProduct === $key) aria-current="page" @endif>{{ $label }}</a>
                    @else
                        <span class="inline-flex min-h-10 items-center gap-2 rounded-control px-3 text-sm font-bold text-subtle" aria-disabled="true" title="{{ __('This product is being connected.') }}">
                            {{ $label }}
                            <span class="rounded-full border border-line px-1.5 py-0.5 text-[9px] font-extrabold uppercase tracking-wide">{{ __('Soon') }}</span>
                        </span>
                    @endif
                @endforeach
            </nav>

            <div class="ml-auto flex shrink-0 items-center gap-1.5 sm:gap-2">
                <details class="ui-topbar-menu group relative hidden max-w-52 md:block" @click.outside="$el.open = false" @keydown.escape.stop="$el.open = false; $el.querySelector('summary')?.focus()">
                    <summary class="inline-flex min-h-10 max-w-52 cursor-pointer list-none items-center gap-2 rounded-control border border-line bg-surface px-3 text-sm font-bold text-ink marker:hidden hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden">
                        <svg class="h-4 w-4 shrink-0 stroke-2 text-primary" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#user-circle"></use></svg>
                        <span class="max-w-32 truncate">{{ $currentWorkspace?->name ?? __('Workspace') }}</span>
                        <svg class="h-3.5 w-3.5 shrink-0 rotate-90 stroke-2 text-muted transition-transform group-open:-rotate-90" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                    </summary>
                    <div class="absolute right-0 top-full z-40 mt-2 grid min-w-64 gap-1 rounded-panel border border-line bg-surface p-2 shadow-panel">
                        <p class="px-3 py-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Switch workspace') }}</p>
                        @foreach ($navigation['workspaces'] ?? [] as $workspace)
                            <form method="POST" action="{{ route('organizations.switch', $workspace) }}">
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
                        <a href="{{ route('organizations.index') }}" class="mt-1 rounded-control border-t border-line px-3 py-3 text-sm font-bold text-primary hover:bg-surface-muted">{{ __('Manage workspace') }}</a>
                    </div>
                </details>

                <x-ui.button type="button" x-ref="paletteToggle" class="ui-btn-sm hidden sm:inline-flex" aria-label="{{ __('Jump to') }}" aria-controls="command-palette" aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K" @click="openPalette($event.currentTarget)">
                    <svg class="h-3.5 w-3.5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#command"></use></svg>
                    <span class="hidden xl:inline">{{ __('Search') }}</span>
                    <kbd class="ui-kbd hidden xl:inline-flex">⌘K</kbd>
                </x-ui.button>
                <x-ui.icon-button label="{{ __('Open quick navigation') }}" x-ref="mobilePaletteToggle" class="sm:hidden" aria-controls="command-palette" aria-haspopup="dialog" @click="openPalette($event.currentTarget)">
                    <svg class="h-[18px] w-[18px] stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#command"></use></svg>
                </x-ui.icon-button>
                <x-ui.icon-button label="{{ __('Notifications') }}" href="{{ route('notifications.index') }}" class="relative" @if(request()->routeIs('notifications.*')) aria-current="page" @endif>
                    <svg class="h-[18px] w-[18px] stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#information-circle"></use></svg>
                    @if (($navigation['unread_notifications'] ?? 0) > 0)
                        <span class="ui-status-dot absolute right-2 top-2" style="--ui-status-dot: var(--ui-danger)" aria-label="{{ __('Unread notifications') }}"></span>
                    @endif
                </x-ui.icon-button>
                <x-ui.icon-button label="{{ __('Use dark theme') }}" data-theme-toggle aria-pressed="false">
                    <svg class="h-[19px] w-[19px] dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.2 15.1A8.5 8.5 0 0 1 8.9 3.8 8.6 8.6 0 1 0 20.2 15.1Z" /></svg>
                    <svg class="hidden h-[19px] w-[19px] dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="3.6" /><path stroke-linecap="round" d="M12 2.5v2M12 19.5v2M4.3 4.3l1.4 1.4m12.6 12.6 1.4 1.4M2.5 12h2m15 0h2M4.3 19.7l1.4-1.4M18.3 5.7l1.4-1.4" /></svg>
                </x-ui.icon-button>
                <details class="ui-topbar-menu group relative" @click.outside="$el.open = false" @keydown.escape.stop="$el.open = false; $el.querySelector('summary')?.focus()">
                    <summary class="flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-control px-1.5 text-sm font-bold text-ink marker:hidden hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden" aria-label="{{ __('Account menu for :name', ['name' => $user?->name]) }}">
                        <x-avatar :name="$user?->name ?? 'User'" class="ui-avatar ui-avatar-sm" />
                        <svg class="hidden h-3.5 w-3.5 rotate-90 stroke-2 text-muted transition-transform group-open:-rotate-90 sm:block" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                    </summary>
                    <div class="absolute right-0 top-full z-40 mt-2 grid min-w-56 gap-1 rounded-panel border border-line bg-surface p-2 shadow-panel">
                        <div class="border-b border-line px-3 pb-3 pt-2">
                            <p class="truncate text-sm font-extrabold text-ink">{{ $user?->name }}</p>
                            <p class="truncate text-xs text-muted">{{ $user?->email }}</p>
                        </div>
                        @foreach ($navigation['profile'] ?? [] as $item)
                            <x-layouts.topbar-navigation-link :item="$item" class="w-full justify-start" />
                        @endforeach
                        @foreach ($navigation['support'] ?? [] as $item)
                            <x-layouts.topbar-navigation-link :item="$item" class="w-full justify-start" />
                        @endforeach
                        <form action="{{ route('logout') }}" method="post" class="mt-1 border-t border-line pt-1">
                            @csrf
                            <button type="submit" class="flex min-h-10 w-full items-center rounded-control px-3 text-left text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ __('Log out') }}</button>
                        </form>
                    </div>
                </details>
            </div>
        </div>

        <div class="flex min-h-14 flex-wrap items-center gap-2 border-t border-line py-2" aria-label="{{ __('Project and environment context') }}">
            <details class="ui-topbar-menu group relative shrink-0" @click.outside="$el.open = false" @keydown.escape.stop="$el.open = false; $el.querySelector('summary')?.focus()">
                <summary class="inline-flex min-h-9 cursor-pointer list-none items-center gap-2 rounded-control border border-line bg-surface px-3 text-left marker:hidden hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden">
                    <span class="text-[10px] font-extrabold uppercase tracking-wide text-subtle">{{ __('Project') }}</span>
                    <span class="max-w-36 truncate text-xs font-extrabold text-ink">{{ data_get($currentProject, 'name') ?? __('All projects') }}</span>
                    <svg class="h-3.5 w-3.5 rotate-90 stroke-2 text-muted transition-transform group-open:-rotate-90" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                </summary>
                <div class="absolute left-0 top-full z-40 mt-2 grid max-h-80 min-w-64 gap-1 overflow-y-auto rounded-panel border border-line bg-surface p-2 shadow-panel">
                    <a href="{{ route('projects.index') }}" @class(['rounded-control px-3 py-2 text-sm font-bold', 'bg-primary-soft text-primary' => ! $currentProjectId, 'text-muted hover:bg-surface-muted hover:text-ink' => $currentProjectId]) @if(! $currentProjectId) aria-current="page" @endif>{{ __('All projects') }}</a>
                    @foreach ($navigation['projects'] ?? [] as $projectOption)
                        <a href="{{ route('projects.show', $projectOption) }}" @class(['rounded-control px-3 py-2 text-sm font-bold', 'bg-primary-soft text-primary' => (string) $currentProjectId === (string) $projectOption->id, 'text-muted hover:bg-surface-muted hover:text-ink' => (string) $currentProjectId !== (string) $projectOption->id]) @if((string) $currentProjectId === (string) $projectOption->id) aria-current="page" @endif>{{ $projectOption->name }}</a>
                    @endforeach
                    <a href="{{ route('projects.index') }}" class="mt-1 border-t border-line px-3 py-2 text-sm font-bold text-primary">{{ __('View all projects') }}</a>
                </div>
            </details>

            <details class="ui-topbar-menu group relative shrink-0" @click.outside="$el.open = false" @keydown.escape.stop="$el.open = false; $el.querySelector('summary')?.focus()">
                <summary class="inline-flex min-h-9 cursor-pointer list-none items-center gap-2 rounded-control border border-line bg-surface px-3 text-left marker:hidden hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus [&::-webkit-details-marker]:hidden">
                    <span class="text-[10px] font-extrabold uppercase tracking-wide text-subtle">{{ __('Environment') }}</span>
                    <span class="max-w-36 truncate text-xs font-extrabold text-ink">{{ data_get($currentEnvironment, 'name') ?? __('All environments') }}</span>
                    <svg class="h-3.5 w-3.5 rotate-90 stroke-2 text-muted transition-transform group-open:-rotate-90" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                </summary>
                @if ($currentProject)
                    <div class="absolute left-0 top-full z-40 mt-2 grid max-h-80 min-w-64 gap-1 overflow-y-auto rounded-panel border border-line bg-surface p-2 shadow-panel">
                        <a href="{{ route('projects.show', $currentProject).'#environment-'.$currentProject->environments->first()?->id.'-heading' }}" class="rounded-control px-3 py-2 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ __('All environments') }}</a>
                        @foreach ($currentProject->environments as $environmentOption)
                            <a href="{{ route('projects.show', $currentProject).'#environment-'.$environmentOption->id.'-heading' }}" @class(['rounded-control px-3 py-2 text-sm font-bold', 'bg-primary-soft text-primary' => data_get($currentEnvironment, 'id') === $environmentOption->id, 'text-muted hover:bg-surface-muted hover:text-ink' => data_get($currentEnvironment, 'id') !== $environmentOption->id]) @if(data_get($currentEnvironment, 'id') === $environmentOption->id) aria-current="page" @endif>{{ $environmentOption->name }}</a>
                        @endforeach
                    </div>
                @else
                    <div class="absolute left-0 top-full z-40 mt-2 max-w-64 rounded-panel border border-line bg-surface p-3 text-xs leading-5 text-muted shadow-panel">{{ __('Choose a project to view its environments.') }}</div>
                @endif
            </details>

        </div>

        <div class="hidden border-t border-line py-2 lg:block">
            <nav class="flex flex-wrap items-center gap-1" aria-label="{{ __(':product sections', ['product' => $activeProductLabel]) }}">
                @foreach ($navigation['groups'] ?? [] as $group)
                    <x-layouts.topbar-navigation-group :group="$group" />
                @endforeach
            </nav>
        </div>
    </div>
</header>
