<div
    class="w-1/2 md:w-1/3 lg:w-64 fixed md:top-0 md:left-0 h-screen lg:block bg-primary border-primary border-r z-30"
    :class="{ 'hidden' : menu === false }"
>
    <div class="w-full h-14 px-4 border-b border-primary flex items-center mb-4">
        <p class="font-bold uppercase text-lg text-primary pl-4 leading-tight">
            {{ config('app.name') }}
        </p>
    </div>
    <div class="mb-4 px-4 space-y-1">
        <p class="pl-4 text-xs font-light mb-1 uppercase text-secondary">
            {{ __('System') }}
        </p>
        <a href="/" @class([
		    "w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer",
		    "bg-primary" => !request()->routeIs('/'),
		    "bg-secondary" => request()->routeIs('/')
		])>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#view-grid"></use>
            </svg>
            <span class="text-primary text-sm font-medium">
                {{ __('Dashboard') }}
            </span>
        </a>
        <a href="{{ route('websites.index') }}" @class([
		    "w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer",
		    "bg-primary" => !request()->routeIs('websites.*'),
		    "bg-secondary" => request()->routeIs('websites.*')
		])>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#link"></use>
            </svg>
            <span class="text-primary text-sm font-medium">
                {{ __('Sites') }}
            </span>
        </a>
        <a href="{{ route('servers.index') }}" @class([
		    "w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer",
		    "bg-primary" => !request()->routeIs('servers.*'),
		    "bg-secondary" => request()->routeIs('servers.*')
		])>
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
		])>
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
		])>
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
        ])>
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
        <a href="{{ route('providers.index') }}" @class([
		    "w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer",
		    "bg-primary" => !request()->routeIs('providers.*'),
		    "bg-secondary" => request()->routeIs('providers.*')
		])>
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
        ])>
            <svg class="w-5 h-5 mr-2 stroke-2">
                <use xlink:href="/assets/images/icons.svg#terminal"></use>
            </svg>
            <span class="text-primary text-sm font-medium">{{ __('Recipes') }}</span>
        </a>
    </div>

    <div class="mb-4 px-4">
        <p class="pl-4 text-xs font-light mb-1 uppercase text-secondary">
            {{ __('Profile') }}
        </p>
        <a href="{{ route('account.index') }}" @class([
            'w-full flex items-center text-ternary py-3 pl-4 hover:bg-secondary rounded-lg cursor-pointer',
            'bg-secondary' => request()->routeIs('account.*'),
            'bg-primary' => ! request()->routeIs('account.*'),
        ])>
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
</div>
