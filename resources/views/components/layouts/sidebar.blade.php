<div
    id="primary-navigation"
    x-cloak
    x-trap.inert.noscroll="menu"
    role="navigation"
    aria-label="{{ __('Primary navigation') }}"
    class="fixed inset-y-0 left-0 z-50 flex h-[100dvh] w-[calc(100vw-3rem)] max-w-xs flex-col overflow-y-auto overscroll-contain border-r border-primary bg-primary pb-[max(1rem,env(safe-area-inset-bottom))] shadow-2xl lg:block lg:h-screen lg:w-64 lg:shadow-none"
    :class="{ 'hidden' : menu === false }"
    @click="if ($event.target.closest('a')) menu = false"
>
    <div class="sticky top-0 z-10 mb-4 flex h-14 w-full items-center justify-between border-b border-primary bg-primary px-4">
        <p class="font-bold uppercase text-lg text-primary pl-4 leading-tight">
            {{ config('app.name') }}
        </p>
        <button
            type="button"
            x-ref="closeNavigation"
            class="button tertiary lg:hidden"
            aria-label="{{ __('Close navigation') }}"
            @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())"
        >
            <svg class="h-4 w-4 stroke-2 text-secondary" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#chevron-left"></use>
            </svg>
        </button>
    </div>
    <form method="GET" action="{{ route('search.index') }}" class="mb-4 flex gap-2 px-4">
        <label for="global-search" class="sr-only">{{ __('Search account') }}</label>
        <input
            id="global-search"
            name="q"
            type="search"
            maxlength="100"
            value="{{ request()->routeIs('search.index') ? request()->string('q') : '' }}"
            placeholder="{{ __('Search or jump to…') }}"
            class="input secondary min-w-0 flex-1 rounded"
        >
        <button type="submit" class="button primary">{{ __('Go') }}</button>
    </form>
    <div class="mb-4 px-4 space-y-1">
        <p class="pl-4 text-xs font-light mb-1 uppercase text-secondary">
            {{ __('System') }}
        </p>
        <a href="{{ route('dashboard') }}" @class([
            'w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer',
            'bg-primary' => ! request()->routeIs('dashboard'),
            'bg-secondary' => request()->routeIs('dashboard'),
        ]) @if (request()->routeIs('dashboard')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#view-grid"></use>
            </svg>
            <span class="text-primary text-sm font-medium">
                {{ __('Dashboard') }}
            </span>
        </a>
        <a href="{{ route('projects.index') }}" @class(['w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer', 'bg-secondary' => request()->routeIs('projects.*') || request()->routeIs('environments.*'), 'bg-primary' => !request()->routeIs('projects.*') && !request()->routeIs('environments.*')])><svg class="w-5 h-5 mr-2 stroke-2"><use xlink:href="/assets/images/icons.svg#view-grid"></use></svg><span class="text-primary text-sm font-medium">{{ __('Applications') }}</span></a>
        @if (auth()->user()->currentOrganization?->permits(auth()->user(), 'manage'))
        <a href="{{ route('system-health.index') }}" @class([
            'w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer',
            'bg-primary' => ! request()->routeIs('system-health.*'),
            'bg-secondary' => request()->routeIs('system-health.*'),
        ]) @if (request()->routeIs('system-health.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#chip"></use>
            </svg>
            <span class="text-primary text-sm font-medium">
                {{ __('System health') }}
            </span>
        </a>
        @endif
        @if (auth()->user()->isPlatformAdmin())
        <a href="{{ route('admin.analytics') }}" @class(['w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer', 'bg-secondary' => request()->routeIs('admin.*'), 'bg-primary' => ! request()->routeIs('admin.*')]) @if (request()->routeIs('admin.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#view-grid"></use></svg>
            <span class="text-primary text-sm font-medium">{{ __('Business analytics') }}</span>
        </a>
        <a href="{{ route('admin.access-requests.index') }}" @class(['w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer', 'bg-secondary' => request()->routeIs('admin.access-requests.*'), 'bg-primary' => ! request()->routeIs('admin.access-requests.*')]) @if (request()->routeIs('admin.access-requests.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#notification"></use></svg>
            <span class="text-primary text-sm font-medium">{{ __('Access requests') }}</span>
        </a>
        @endif
        <a href="{{ route('observability.index') }}" @class(['w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer', 'bg-secondary' => request()->routeIs('observability.*'), 'bg-primary' => !request()->routeIs('observability.*')])><svg class="w-5 h-5 mr-2 stroke-2"><use xlink:href="/assets/images/icons.svg#chip"></use></svg><span class="text-primary text-sm font-medium">{{ __('Observability') }}</span></a>
        <a href="{{ route('backups.index') }}" @class(['w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer', 'bg-secondary' => request()->routeIs('backups.*'), 'bg-primary' => !request()->routeIs('backups.*')])><svg class="w-5 h-5 mr-2 stroke-2"><use xlink:href="/assets/images/icons.svg#database"></use></svg><span class="text-primary text-sm font-medium">{{ __('Backups') }}</span></a>
        <a href="{{ route('costs.index') }}" @class(['w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer', 'bg-secondary' => request()->routeIs('costs.*'), 'bg-primary' => !request()->routeIs('costs.*')])><svg class="w-5 h-5 mr-2 stroke-2"><use xlink:href="/assets/images/icons.svg#chip"></use></svg><span class="text-primary text-sm font-medium">{{ __('Costs') }}</span></a>
        <a href="{{ route('databases.index') }}" @class(['w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer', 'bg-secondary' => request()->routeIs('databases.*'), 'bg-primary' => !request()->routeIs('databases.*')])><svg class="w-5 h-5 mr-2 stroke-2"><use xlink:href="/assets/images/icons.svg#database"></use></svg><span class="text-primary text-sm font-medium">{{ __('Databases') }}</span></a>
        <a href="{{ route('load-balancers.index') }}" @class(['w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer', 'bg-secondary' => request()->routeIs('load-balancers.*'), 'bg-primary' => !request()->routeIs('load-balancers.*')])><svg class="w-5 h-5 mr-2 stroke-2"><use xlink:href="/assets/images/icons.svg#cloud"></use></svg><span class="text-primary text-sm font-medium">{{ __('High availability') }}</span></a>
        <a href="{{ route('activity.index') }}" @class([
            'w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer',
            'bg-primary' => ! request()->routeIs('activity.*'),
            'bg-secondary' => request()->routeIs('activity.*'),
        ]) @if (request()->routeIs('activity.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#clock"></use>
            </svg>
            <span class="text-primary text-sm font-medium">
                {{ __('Activity') }}
            </span>
        </a>
        <a href="{{ route('commands.index') }}" @class([
            'w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer',
            'bg-primary' => ! request()->routeIs('commands.*'),
            'bg-secondary' => request()->routeIs('commands.*'),
        ]) @if (request()->routeIs('commands.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#tasks"></use>
            </svg>
            <span class="text-primary text-sm font-medium">{{ __('Commands') }}</span>
        </a>
        <a href="{{ route('websites.index') }}" @class([
		    "w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer",
		    "bg-primary" => !request()->routeIs('websites.*'),
		    "bg-secondary" => request()->routeIs('websites.*')
		]) @if (request()->routeIs('websites.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#link"></use>
            </svg>
            <span class="text-primary text-sm font-medium">
                {{ __('Sites') }}
            </span>
        </a>
        <a href="{{ route('domains.index') }}" @class(['w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer', 'bg-secondary' => request()->routeIs('domains.*'), 'bg-primary' => !request()->routeIs('domains.*')]) @if(request()->routeIs('domains.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#link"></use></svg>
            <span class="text-primary text-sm font-medium">{{ __('Domains') }}</span>
        </a>
        <a href="{{ route('servers.index') }}" @class([
		    "w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer",
		    "bg-primary" => !request()->routeIs('servers.*'),
		    "bg-secondary" => request()->routeIs('servers.*')
		]) @if (request()->routeIs('servers.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#cloud"></use>
            </svg>
            <span class="text-primary text-sm font-medium">
                {{ __('Servers') }}
            </span>
        </a>
        <a href="{{ route('builds.index') }}" @class([
		    "w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer",
		    "bg-primary" => !request()->routeIs('builds.*'),
		    "bg-secondary" => request()->routeIs('builds.*')
		]) @if (request()->routeIs('builds.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#cloud-upload"></use>
            </svg>
            <span class="text-primary text-sm font-medium">
                {{ __('Builds') }}
            </span>
        </a>
        <a href="{{ route('repositories.index') }}" @class([
		    "w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer",
		    "bg-primary" => !request()->routeIs('repositories.*'),
		    "bg-secondary" => request()->routeIs('repositories.*')
		]) @if (request()->routeIs('repositories.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#code"></use>
            </svg>
            <span class="text-primary text-sm font-medium">
                {{ __('Repositories') }}
            </span>
        </a>
        <a href="{{ route('notifications.index') }}" @class([
            'w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer',
            'bg-primary' => ! request()->routeIs('notifications.*'),
            'bg-secondary' => request()->routeIs('notifications.*'),
        ]) @if (request()->routeIs('notifications.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#information-circle"></use>
            </svg>
            <span class="text-primary text-sm font-medium">{{ __('Notifications') }}</span>
            @if ($unreadNotificationCount = auth()->user()->unreadNotifications()->count())
                <span
                    class="ml-auto mr-3 rounded-full bg-red-600 px-2 py-0.5 text-xs font-semibold text-white"
                    aria-label="{{ __('Unread notifications: :count', ['count' => $unreadNotificationCount]) }}"
                >
                    {{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}
                </span>
            @endif
        </a>
        <a href="{{ route('automation.index') }}" @class(['w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer', 'bg-secondary' => request()->routeIs('automation.*'), 'bg-primary' => !request()->routeIs('automation.*')])>
            <svg class="w-5 h-5 mr-2 stroke-2"><use xlink:href="/assets/images/icons.svg#terminal"></use></svg><span class="text-primary text-sm font-medium">{{ __('Automation') }}</span>
        </a>
        <a href="{{ route('providers.index') }}" @class([
		    "w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer",
		    "bg-primary" => !request()->routeIs('providers.*'),
		    "bg-secondary" => request()->routeIs('providers.*')
		]) @if (request()->routeIs('providers.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#share"></use>
            </svg>
            <span class="text-primary text-sm font-medium">
                {{ __('Providers') }}
            </span>
        </a>
        <a href="{{ route('recipes.index') }}" @class([
            'w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer',
            'bg-primary' => ! request()->routeIs('recipes.*'),
            'bg-secondary' => request()->routeIs('recipes.*'),
        ]) @if (request()->routeIs('recipes.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#terminal"></use>
            </svg>
            <span class="text-primary text-sm font-medium">{{ __('Recipes') }}</span>
        </a>
        <a href="{{ route('gallery.index') }}" @class([
            'w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer',
            'bg-primary' => ! request()->routeIs('gallery.*'),
            'bg-secondary' => request()->routeIs('gallery.*'),
        ]) @if (request()->routeIs('gallery.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#view-grid"></use>
            </svg>
            <span class="text-primary text-sm font-medium">{{ __('Gallery') }}</span>
        </a>
    </div>

    <div class="mt-auto px-4">
        <a href="{{ route('feedback.index', ['from' => '/'.request()->path()]) }}" class="mb-1 flex w-full items-center rounded-lg py-3 pl-4 text-ternary hover:bg-secondary"><svg class="mr-2 h-5 w-5 stroke-2"><use xlink:href="/assets/images/icons.svg#information-circle"></use></svg><span class="text-sm font-medium text-primary">{{ __('Send feedback') }}</span></a>
        <a href="{{ route('docs') }}" class="mb-3 flex w-full items-center rounded-lg py-3 pl-4 text-ternary hover:bg-secondary"><svg class="mr-2 h-5 w-5 stroke-2"><use xlink:href="/assets/images/icons.svg#information-circle"></use></svg><span class="text-sm font-medium text-primary">{{ __('Help and guides') }}</span></a>
        <p class="pl-4 text-xs font-light mb-1 uppercase text-secondary">
            {{ __('Profile') }}
        </p>
        <a href="{{ route('organizations.index') }}" @class(['w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer', 'bg-secondary' => request()->routeIs('organizations.*'), 'bg-primary' => !request()->routeIs('organizations.*')])><svg class="w-5 h-5 mr-2 stroke-2"><use xlink:href="/assets/images/icons.svg#user-circle"></use></svg><span class="text-primary text-sm font-medium">{{ __('Workspace') }}</span></a>
        <a href="{{ route('billing.index') }}" @class(['w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer', 'bg-secondary' => request()->routeIs('billing.*'), 'bg-primary' => ! request()->routeIs('billing.*')])>
            <svg class="w-5 h-5 mr-2 stroke-2"><use xlink:href="/assets/images/icons.svg#cog"></use></svg><span class="text-primary text-sm font-medium">{{ __('Billing') }}</span>
        </a>
        <a href="{{ route('account.index') }}" @class([
            'w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer',
            'bg-secondary' => request()->routeIs('account.*'),
            'bg-primary' => ! request()->routeIs('account.*'),
        ]) @if (request()->routeIs('account.*')) aria-current="page" @endif>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#user-circle"></use>
            </svg>
            <span class="text-primary text-sm font-medium">
                {{ __('Account') }}
            </span>
        </a>
        <a href="{{ route('account.index') }}#password" class="w-full flex items-center text-ternary bg-primary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer">
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#cog"></use>
            </svg>
            <span class="text-primary text-sm font-medium">
                {{ __('Settings') }}
            </span>
        </a>
    </div>

    <div data-mobile-account class="mx-4 mt-4 border-t border-primary pt-4 lg:hidden">
        <div class="flex min-w-0 items-center gap-3 px-2">
            <x-avatar :name="auth()->user()->name" class="h-9 w-9 rounded text-xs" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-primary">{{ auth()->user()->name }}</p>
                <p class="truncate text-xs text-secondary">{{ auth()->user()->email }}</p>
            </div>
        </div>
        <form action="{{ route('logout') }}" method="post" class="mt-4">
            @csrf
            <button type="submit" class="button tertiary w-full justify-center">{{ __('Logout') }}</button>
        </form>
    </div>
</div>
