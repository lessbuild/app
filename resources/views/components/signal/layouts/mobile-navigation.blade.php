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
    :breakpoint="1024"
    desktop-navigation="#signal-product-navigation"
>
    <div class="grid gap-1">
        <p class="px-3 py-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Products') }}</p>
        @if ($activeProduct === 'core' && $currentWorkspace && \Illuminate\Support\Facades\Route::has('core.workspace.dashboard'))
            <x-signal.layouts.navigation-link :item="['label' => __('Overview'), 'href' => route('core.workspace.dashboard', $currentWorkspace), 'active' => request()->routeIs('core.workspace.dashboard')]" class="w-full justify-start" />
        @endif
        <a class="flex min-h-10 items-center rounded-control px-3 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink" href="{{ $projectsUrl }}">{{ __('Projects') }}</a>
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

        @if (count($workspaceOptions))
            <p class="px-3 pb-1 pt-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Switch workspace') }}</p>
            @foreach ($workspaceOptions as $workspace)
                <form method="POST" action="{{ route($workspaceSwitchRoute, $workspace) }}">
                    @csrf
                    <button type="submit" class="flex min-h-10 w-full items-center rounded-control px-3 text-left text-sm font-bold {{ $currentWorkspace?->id === $workspace->id ? 'bg-primary-soft text-primary' : 'text-muted hover:bg-surface-muted hover:text-ink' }}" @if($currentWorkspace?->id === $workspace->id) aria-current="true" @endif>{{ $workspace->name }}</button>
                </form>
            @endforeach
            @if ($workspaceManageUrl)
                <a href="{{ $workspaceManageUrl }}" class="flex min-h-10 items-center rounded-control px-3 text-sm font-bold text-primary hover:bg-surface-muted">{{ __('Manage workspace') }}</a>
            @endif
        @endif

        @if ($showProjectContext)
            <p class="px-3 pb-1 pt-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ $contextLabel }}</p>
            <a href="{{ $contextIndexUrl }}" class="flex min-h-10 items-center rounded-control px-3 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ $contextIndexLabel }}</a>
            @foreach ($contextOptions as $contextOption)
                @if ($contextOptionHref = data_get($contextOption, 'href'))
                    <a href="{{ $contextOptionHref }}" class="flex min-h-10 items-center rounded-control px-3 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ data_get($contextOption, 'name') }}</a>
                @endif
            @endforeach
        @endif

        @if ($showEnvironmentContext && count($environmentOptions))
            <p class="px-3 pb-1 pt-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('Environment') }}</p>
            @if ($environmentContextUnavailable)
                <p class="px-3 py-2 text-xs font-bold text-warning" role="status">{{ __('Selected environment unavailable') }}</p>
            @endif
            <a href="{{ $environmentIndexUrl }}" class="flex min-h-10 items-center rounded-control px-3 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ __('All environments') }}</a>
            @foreach ($environmentOptions as $environmentOption)
                @if ($environmentOptionHref = data_get($environmentOption, 'href'))
                    <a href="{{ $environmentOptionHref }}" class="flex min-h-10 items-center rounded-control px-3 text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ data_get($environmentOption, 'name') }}</a>
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
                <button type="submit" class="flex min-h-10 w-full items-center rounded-control px-3 text-left text-sm font-bold text-muted hover:bg-surface-muted hover:text-ink">{{ __('Log out') }}</button>
            </form>
        @endif
    </div>
</x-signal.layouts.mobile-sidebar>
