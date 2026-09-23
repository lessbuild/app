@props(['navigation' => []])

@php
    $products = config('platform.products', []);
    $currentHost = request()->getHost();
    $activeProduct = collect($products)->keys()->first(fn (string $key): bool =>
        request()->routeIs($key.'.*')
        || (filled($products[$key]['host'] ?? null) && strcasecmp((string) $products[$key]['host'], $currentHost) === 0)
    ) ?? 'deployer';
    $activeProductLabel = $products[$activeProduct]['label'] ?? __('Deployer');
@endphp

<section
    id="app-mobile-nav"
    data-mobile-navigation
    x-cloak
    x-show="menu"
    x-trap.inert.noscroll="menu"
    @resize.window="if (window.innerWidth >= 1024) menu = false"
    class="fixed inset-0 z-50 lg:hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="app-mobile-nav-title"
>
    <button type="button" class="absolute inset-0 bg-slate-950/50" aria-label="{{ __('Close navigation') }}" @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())"></button>
    <aside class="relative flex h-full w-[min(22rem,calc(100vw-2rem))] flex-col overflow-y-auto bg-surface px-4 py-5 shadow-2xl">
        <div class="flex items-center justify-between gap-3 pr-12">
            <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3 text-base font-extrabold tracking-tight text-ink" aria-label="{{ config('app.name') }} home">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-ink text-surface shadow-soft"><img src="{{ asset('favicon.svg') }}" alt="" class="h-5 w-5 rounded-md"></span>
                <span class="truncate">{{ config('app.name') }}</span>
            </a>
            <button type="button" x-ref="closeNavigation" class="ui-icon-btn absolute right-3 top-3 z-10" aria-label="{{ __('Close navigation') }}" @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())">
                <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#close"></use></svg>
            </button>
        </div>

        <h2 id="app-mobile-nav-title" class="sr-only">{{ __('Application navigation') }}</h2>

        <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm mt-6 w-full justify-between" aria-controls="command-palette" aria-haspopup="dialog" @click="openPalette($event.currentTarget); menu = false">
            <span>{{ __('Search or jump to…') }}</span><kbd class="ui-kbd">⌘K</kbd>
        </button>

        <nav class="mt-6 border-b border-line pb-5" aria-label="{{ __('Products') }}">
            <p class="mb-2 px-3 text-[10px] font-extrabold uppercase tracking-[0.18em] text-subtle">{{ __('Platform') }}</p>
            <div class="grid gap-1">
                <a href="{{ route('projects.index') }}" @class(['app-sidebar-link', 'bg-primary-soft text-primary' => request()->routeIs('projects.*')]) @if(request()->routeIs('projects.*')) aria-current="page" @endif>
                    <svg class="h-4 w-4 shrink-0 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#view-grid"></use></svg><span>{{ __('Projects') }}</span>
                </a>
                @foreach (['deployer' => ['label' => __('Deployer'), 'route' => 'dashboard'], 'monitor' => ['label' => __('Monitor')], 'analytics' => ['label' => __('Analytics')]] as $key => $product)
                    @php($label = $product['label'])
                    @if ($key === 'deployer')
                        <a href="{{ route($product['route']) }}" @class(['app-sidebar-link', 'bg-primary-soft text-primary' => $activeProduct === $key]) @if($activeProduct === $key) aria-current="page" @endif>
                            <svg class="h-4 w-4 shrink-0 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#cloud-upload"></use></svg><span>{{ $label }}</span>
                        </a>
                    @elseif (($products[$key]['enabled'] ?? false) && filled($products[$key]['url'] ?? null))
                        <a href="{{ $products[$key]['url'] }}" class="app-sidebar-link" @if($activeProduct === $key) aria-current="page" @endif><span>{{ $label }}</span></a>
                    @else
                        <span class="app-sidebar-link cursor-not-allowed text-subtle" aria-disabled="true">{{ $label }} <span class="ml-auto text-[10px] uppercase">{{ __('Soon') }}</span></span>
                    @endif
                @endforeach
            </div>
        </nav>

        <details class="mt-5 rounded-panel border border-line bg-surface-muted p-3">
            <summary class="cursor-pointer list-none text-xs font-extrabold text-ink marker:hidden [&::-webkit-details-marker]:hidden">{{ auth()->user()->currentOrganization?->name ?: __('Your workspace') }}</summary>
            <div class="mt-3 grid gap-1">
                @foreach ($navigation['workspaces'] ?? [] as $workspace)
                    <form method="POST" action="{{ route('organizations.switch', $workspace) }}">
                        @csrf
                        <button type="submit" class="w-full rounded-control px-3 py-2 text-left text-xs font-bold text-muted hover:bg-surface hover:text-ink" @if(auth()->user()->current_organization_id === $workspace->id) aria-current="true" @endif>{{ $workspace->name }}</button>
                    </form>
                @endforeach
                <a href="{{ route('organizations.index') }}" class="rounded-control px-3 py-2 text-xs font-bold text-primary hover:bg-surface">{{ __('Manage workspace') }}</a>
            </div>
        </details>

        <nav class="mt-5 flex-1" aria-label="{{ __(':product sections', ['product' => $activeProductLabel]) }}">
            @foreach ($navigation['mobile']['groups'] ?? [] as $group)
                <section class="{{ $loop->first ? '' : 'mt-5' }}" aria-labelledby="mobile-navigation-group-{{ $loop->index }}">
                    <p id="mobile-navigation-group-{{ $loop->index }}" class="mb-3 px-3 text-[10px] font-extrabold uppercase tracking-[0.18em] text-subtle">
                        {{ $loop->first ? __('Workspace') : __('Settings and support') }}
                    </p>
                    <div class="grid gap-1">
                        @foreach ($group as $item)
                            <x-layouts.partials.navigation-link :item="$item" />
                        @endforeach
                    </div>
                </section>
            @endforeach
        </nav>

        <div class="mt-5 border-t border-line pt-4">
            @foreach ([...($navigation['profile'] ?? []), ...($navigation['support'] ?? [])] as $item)
                <x-layouts.partials.navigation-link :item="$item" />
            @endforeach
            <div class="mt-3 flex min-w-0 items-center gap-3 rounded-xl bg-surface-muted p-3">
                <x-avatar :name="auth()->user()->name" class="ui-avatar ui-avatar-sm" />
                <div class="min-w-0"><p class="truncate text-xs font-bold text-ink">{{ auth()->user()->name }}</p><p class="truncate text-[11px] text-muted">{{ auth()->user()->email }}</p></div>
            </div>
            <form action="{{ route('logout') }}" method="post" class="mt-3">
                @csrf
                <x-ui.button type="submit" variant="ghost" class="w-full justify-start px-3">{{ __('Log out') }}</x-ui.button>
            </form>
        </div>
    </aside>
</section>
