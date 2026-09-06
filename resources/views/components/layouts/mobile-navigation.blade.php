@php
    $mobileGroups = [
        [
            ['Dashboard', 'dashboard', 'view-grid'], ['Applications', 'projects.index', 'view-grid'],
            ['Websites', 'websites.index', 'link'], ['Servers', 'servers.index', 'cloud'],
            ['Deployments', 'builds.index', 'cloud-upload'], ['Repositories', 'repositories.index', 'code'],
            ['Domains', 'domains.index', 'link'], ['Databases', 'databases.index', 'database'],
            ['Backups', 'backups.index', 'database'], ['High availability', 'load-balancers.index', 'cloud'],
            ['Observability', 'observability.index', 'chip'], ['Activity', 'activity.index', 'information-circle'],
            ['Commands', 'commands.index', 'code'], ['Automation', 'automation.index', 'code'],
            ['Providers', 'providers.index', 'cloud'], ['Recipes', 'recipes.index', 'code'],
            ['Gallery', 'gallery.index', 'view-grid'], ['Alerts', 'notifications.index', 'information-circle'],
        ],
        [
            ['Workspace', 'organizations.index', 'user-circle'], ['Costs', 'costs.index', 'chip'],
            ['Billing', 'billing.index', 'information-circle'], ['Account', 'account.index', 'user-circle'],
            ['Help and guides', 'docs', 'information-circle'], ['Send feedback', 'feedback.index', 'information-circle'],
        ],
    ];
    if (auth()->user()->currentOrganization?->permits(auth()->user(), 'manage')) $mobileGroups[1][] = ['System health', 'system-health.index', 'chip'];
    if (auth()->user()->isPlatformAdmin()) {
        $mobileGroups[1][] = ['Business analytics', 'admin.analytics', 'chip'];
        $mobileGroups[1][] = ['Access requests', 'admin.access-requests.index', 'user-circle'];
    }
@endphp
<section id="primary-navigation" x-cloak x-show="menu" x-trap.inert.noscroll="menu" @resize.window="if (window.innerWidth >= 1024) menu = false" class="fixed inset-0 z-[60] flex h-[100dvh] flex-col bg-primary lg:hidden" role="dialog" aria-modal="true" aria-label="{{ __('Primary navigation') }}">
    <div class="flex shrink-0 items-center gap-3 border-b border-gray-700 bg-gray-800 px-4 py-3 text-gray-100 shadow-xs">
        <a href="{{ route('dashboard') }}" class="max-w-[35%] truncate text-sm font-bold text-white">{{ config('app.name') }}</a>
        <form method="GET" action="{{ route('search.index') }}" class="min-w-0 flex-1"><label for="mobile-workspace-search" class="sr-only">{{ __('Search workspace') }}</label><input id="mobile-workspace-search" type="search" name="q" maxlength="100" placeholder="{{ __('Search workspace') }}" class="h-11 w-full rounded-lg border border-gray-600 bg-gray-700 px-3 text-sm text-gray-100 placeholder-gray-300"></form>
        <button type="button" x-ref="closeNavigation" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-2xl text-white hover:bg-gray-700" aria-label="{{ __('Close navigation') }}" @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())">×</button>
    </div>
    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
        <a href="{{ route('organizations.index') }}" class="mb-4 flex items-center gap-3 rounded-xl border border-primary p-3 shadow-xs"><x-avatar :name="auth()->user()->currentOrganization?->name ?: config('app.name')" class="h-10 w-10 shrink-0 rounded-lg" /><span class="min-w-0"><span class="block text-[10px] font-bold uppercase tracking-wide text-secondary">{{ __('Current workspace') }}</span><span class="block truncate text-sm font-bold text-primary">{{ auth()->user()->currentOrganization?->name ?: config('app.name') }}</span></span></a>
        @foreach($mobileGroups as $group)
            <nav class="grid grid-cols-2 gap-2 border-b border-primary pb-4 mb-4" aria-label="{{ $loop->first ? __('Workspace navigation') : __('Settings and support') }}">
                @foreach($group as [$label, $route, $icon])
                    @php($active = request()->routeIs($route === 'dashboard' ? 'dashboard' : preg_replace('/\.[^.]+$/', '.*', $route)) || ($route === 'projects.index' && request()->routeIs('environments.*')))
                    <a href="{{ route($route) }}" @if($active) aria-current="page" @endif @class(['flex min-h-[46px] min-w-0 items-center gap-2 rounded-lg border px-3 py-2 text-xs font-semibold shadow-xs', 'border-slate-700 bg-slate-700 text-white' => $active, 'border-primary bg-primary text-primary hover:bg-secondary' => !$active])><svg class="h-4 w-4 shrink-0 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#{{ $icon }}"></use></svg><span class="break-words">{{ __($label) }}</span></a>
                @endforeach
            </nav>
        @endforeach
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="button secondary w-full min-h-[44px]">{{ __('Logout') }}</button></form>
    </div>
</section>
