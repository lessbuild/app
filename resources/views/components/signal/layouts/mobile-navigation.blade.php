@props([
    'id',
    'activeProduct',
    'activeProductLabel',
    'brandUrl',
    'platformSsoHref',
    'products' => [],
    'projectsUrl',
    'productUrlOverrides' => null,
    'currentWorkspace' => null,
    'workspaceOptions' => [],
    'workspaceSwitchRoute' => 'organizations.switch',
    'workspaceManageUrl' => null,
    'showProjectContext' => true,
    'contextLabel' => 'Project',
    'contextIndexLabel' => 'All projects',
    'contextIndexUrl' => null,
    'contextOptions' => [],
    'showEnvironmentContext' => true,
    'environmentOptions' => [],
    'environmentContextUnavailable' => false,
    'environmentIndexUrl' => null,
    'navigation' => [],
    'logoutUrl' => null,
])

<x-signal.layouts.mobile-sidebar
    :id="$id"
    :title="__('Application navigation')"
    :brand-url="$brandUrl"
    :breakpoint="1280"
    desktop-navigation="#signal-product-navigation"
>
    <div class="grid gap-1">
        <p class="px-3 py-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Products') }}</p>
        @if ($activeProduct === 'core' && $currentWorkspace && \Illuminate\Support\Facades\Route::has('core.workspace.dashboard'))
            <x-signal.layouts.navigation-link :item="['label' => __('Overview'), 'href' => route('core.workspace.dashboard', $currentWorkspace), 'active' => request()->routeIs('core.workspace.dashboard')]" class="w-full justify-start" />
        @endif
        @if ($activeProduct === 'core' && $currentWorkspace && \Illuminate\Support\Facades\Route::has('core.workspace.subscriptions'))
            <x-signal.layouts.navigation-link :item="['label' => __('Plans'), 'href' => route('core.workspace.subscriptions', $currentWorkspace), 'active' => request()->routeIs('core.workspace.subscriptions')]" class="w-full justify-start" />
        @endif
        @if ($activeProduct === 'core' && $currentWorkspace && \Illuminate\Support\Facades\Route::has('core.workspace.admin'))
            <x-signal.layouts.navigation-link :item="['label' => __('Manage'), 'href' => route('core.workspace.admin', $currentWorkspace), 'active' => request()->routeIs('core.workspace.admin')]" class="w-full justify-start" />
        @endif
        @if ($activeProduct === 'core' && $currentWorkspace && \Illuminate\Support\Facades\Route::has('core.workspace.costs'))
            <x-signal.layouts.navigation-link :item="['label' => __('Costs'), 'href' => route('core.workspace.costs', $currentWorkspace), 'active' => request()->routeIs('core.workspace.costs')]" class="w-full justify-start" />
        @endif
        @if ($activeProduct === 'core' && $currentWorkspace && \Illuminate\Support\Facades\Route::has('core.workspace.feedback.index'))
            <x-signal.layouts.navigation-link :item="['label' => __('Feedback'), 'href' => route('core.workspace.feedback.index', $currentWorkspace), 'active' => request()->routeIs('core.workspace.feedback.*')]" class="w-full justify-start" />
        @endif
        @if (\Illuminate\Support\Facades\Route::has('core.help'))
            <x-signal.layouts.navigation-link :item="['label' => __('Help and guides'), 'href' => route('core.help'), 'active' => request()->routeIs('core.help')]" class="w-full justify-start" />
        @endif
        <x-signal.layouts.navigation-link :item="['label' => __('Projects'), 'href' => $projectsUrl, 'active' => request()->routeIs('projects.*', 'core.projects.*')]" class="w-full justify-start" />
        @foreach (['deployer', 'monitor', 'analytics'] as $productKeyOption)
            @php
                $productConfig = $products[$productKeyOption] ?? [];
                $productRoute = $productKeyOption.'.dashboard';
                if ($productKeyOption === 'deployer') $productRoute = 'dashboard';
                $productHref = is_array($productUrlOverrides) && array_key_exists($productKeyOption, $productUrlOverrides)
                    ? $productUrlOverrides[$productKeyOption]
                    : (\Illuminate\Support\Facades\Route::has($productRoute)
                        ? route($productRoute)
                        : ($productConfig['url'] ?? null));
                $productHref = $platformSsoHref($productHref);
            @endphp
            @if ($productHref)
                <x-signal.layouts.navigation-link
                    :item="['label' => $productConfig['label'] ?? ucfirst($productKeyOption), 'href' => $productHref, 'active' => $activeProduct === $productKeyOption]"
                    class="w-full justify-start"
                />
            @endif
        @endforeach

        @if (count($workspaceOptions) || $workspaceManageUrl)
            <x-signal.layouts.workspace-switcher
                :current-workspace="$currentWorkspace"
                :workspace-options="$workspaceOptions"
                :switch-route="$workspaceSwitchRoute"
                :manage-url="$workspaceManageUrl"
                variant="mobile"
            />
        @endif

        @if ($showProjectContext)
            <p class="px-3 pb-1 pt-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ $contextLabel }}</p>
            <x-signal.ui.link href="{{ $contextIndexUrl }}" variant="muted" class="w-full justify-start">{{ $contextIndexLabel }}</x-signal.ui.link>
            @foreach ($contextOptions as $contextOption)
                @if ($contextOptionHref = data_get($contextOption, 'href'))
                    <x-signal.ui.link href="{{ $contextOptionHref }}" variant="muted" class="w-full justify-start">{{ data_get($contextOption, 'name') }}</x-signal.ui.link>
                @endif
            @endforeach
        @endif

        @if ($showEnvironmentContext && count($environmentOptions))
            <p class="px-3 pb-1 pt-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Environment') }}</p>
            @if ($environmentContextUnavailable)
                <p class="px-3 py-2 text-xs font-bold text-warning" role="status">{{ __('Selected environment unavailable') }}</p>
            @endif
            <x-signal.ui.link href="{{ $environmentIndexUrl }}" variant="muted" class="w-full justify-start">{{ __('All environments') }}</x-signal.ui.link>
            @foreach ($environmentOptions as $environmentOption)
                @if ($environmentOptionHref = data_get($environmentOption, 'href'))
                    <x-signal.ui.link href="{{ $environmentOptionHref }}" variant="muted" class="w-full justify-start">{{ data_get($environmentOption, 'name') }}</x-signal.ui.link>
                @endif
            @endforeach
        @endif

        <div class="my-1 border-t border-line"></div>
        @php($mobileGroups = data_get($navigation, 'mobile.groups'))
        @if (is_array($mobileGroups))
            <nav id="signal-mobile-product-navigation" class="grid gap-1" aria-label="{{ __(':product sections', ['product' => $activeProductLabel]) }}">
                @foreach ($mobileGroups[0] ?? [] as $item)
                    <x-signal.layouts.navigation-link :item="$item" class="w-full justify-start" />
                @endforeach
                @foreach ($mobileGroups[2] ?? [] as $item)
                    <x-signal.layouts.navigation-link :item="$item" class="w-full justify-start" />
                @endforeach
            </nav>
            <nav id="signal-mobile-profile-navigation" class="mt-1 grid gap-1 border-t border-line pt-1" aria-label="{{ __('Account and support') }}">
                @foreach ($mobileGroups[1] ?? [] as $item)
                    <x-signal.layouts.navigation-link :item="$item" class="w-full justify-start" />
                @endforeach
            </nav>
        @else
            <nav id="signal-mobile-product-navigation" class="grid gap-1" aria-label="{{ __(':product sections', ['product' => $activeProductLabel]) }}">
                @foreach ($navigation['groups'] ?? [] as $group)
                    <p class="px-3 pb-1 pt-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ $group['label'] }}</p>
                    @foreach ($group['items'] ?? [] as $item)
                        <x-signal.layouts.navigation-link :item="$item" class="w-full justify-start" />
                    @endforeach
                @endforeach
            </nav>
            <nav id="signal-mobile-profile-navigation" class="mt-1 grid gap-1 border-t border-line pt-1" aria-label="{{ __('Account and support') }}">
                @foreach (array_merge($navigation['profile'] ?? [], $navigation['support'] ?? []) as $item)
                    <x-signal.layouts.navigation-link :item="$item" class="w-full justify-start" />
                @endforeach
            </nav>
        @endif

        @if ($logoutUrl)
            <form action="{{ $logoutUrl }}" method="post" class="mt-1 border-t border-line pt-1">
                @csrf
                <x-signal.ui.button type="submit" variant="quiet" class="min-h-10 w-full justify-start rounded-control px-3 text-left text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ __('Log out') }}</x-signal.ui.button>
            </form>
        @endif
    </div>
</x-signal.layouts.mobile-sidebar>
