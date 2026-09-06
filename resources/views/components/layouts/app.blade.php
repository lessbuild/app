<x-layouts.core>
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded focus:bg-primary focus:px-4 focus:py-3 focus:font-semibold focus:text-primary focus:shadow-xl">
        {{ __('Skip to main content') }}
    </a>
    <div
        class="flex flex-wrap overflow-x-hidden pb-[calc(4.5rem+env(safe-area-inset-bottom))] lg:pb-0"
        x-data="{ menu: false, palette: false, paletteQuery: '', paletteIndex: 0 }"
        @keydown.escape.window="if (palette) { palette = false; $nextTick(() => $refs.paletteToggle?.focus()) } else if (menu) { menu = false; $nextTick(() => $refs.navigationToggle.focus()) }"
        @keydown.window.prevent.cmd.k="palette = true; paletteQuery = ''; paletteIndex = 0; $nextTick(() => $refs.paletteInput.focus())"
        @keydown.window.prevent.ctrl.k="palette = true; paletteQuery = ''; paletteIndex = 0; $nextTick(() => $refs.paletteInput.focus())"
    >

        <!--
         ! ------------------------------------------------------------
         ! Load the navigation
         ! ------------------------------------------------------------
         !-->
        <button
            type="button"
            class="fixed inset-0 z-40 bg-black bg-opacity-40 lg:hidden"
            style="display: none"
            x-show="menu"
            aria-label="{{ __('Close navigation') }}"
            @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())"
        ></button>
        <x-layouts.sidebar />
        <x-layouts.mobile-navigation />

        <!--
         ! ------------------------------------------------------------
         ! Website main content
         ! ------------------------------------------------------------
         !-->
        <main id="main-content" tabindex="-1" class="min-w-0 w-full bg-secondary pl-0 lg:pl-64 min-h-screen">
            <div class="sticky top-0 z-30 bg-gray-800 text-gray-100">
                <div class="flex h-16 items-center justify-between px-4 lg:hidden">
                    <a href="{{ route('dashboard') }}" class="font-bold text-lg text-white">{{ config('app.name') }}</a>
                    <button type="button" x-ref="navigationToggle" class="flex min-h-[44px] items-center gap-2 rounded-lg border border-gray-600 bg-gray-700 px-3 text-sm font-semibold text-gray-100 shadow-sm hover:bg-gray-600" aria-controls="primary-navigation" :aria-expanded="menu.toString()" aria-label="{{ __('Toggle navigation') }}" @click="menu = true; $nextTick(() => $refs.closeNavigation.focus())"><svg class="h-4 w-4 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#menu"></use></svg>{{ __('Menu') }}</button>
                </div>
                <div class="w-full h-14 px-6 border-b border-gray-700 hidden lg:flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="hidden sm:block">
                            <button type="button" x-ref="paletteToggle" class="inline-flex items-center justify-center rounded border border-gray-600 bg-gray-700 px-3 py-2 text-xs font-medium text-gray-100 hover:bg-gray-600" @click="palette = true; paletteQuery = ''; paletteIndex = 0; $nextTick(() => $refs.paletteInput.focus())"><span>{{ __('Search and navigate') }}</span><kbd class="ml-3 rounded border border-gray-500 px-1.5 py-0.5 text-[10px] text-gray-200">⌘K</kbd></button>
                        </div>
                    </div>
                    <div class="flex items-center relative">
                        <a href="{{ route('account.index') }}" aria-label="{{ __('Account settings') }}">
                            <x-avatar :name="auth()->user()->name" class="h-6 w-6 rounded text-[10px] shadow-lg" />
                        </a>

                        <form action="{{ route('logout') }}" method="post" class="ml-4">
                            @csrf
                            <button type="submit" class="button tertiary">{{ __('Logout') }}</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="p-6 mb-20">
                <x-alerts.flash />
                {{ $slot }}
            </div>
        </main>

        <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 overflow-hidden border-t border-primary bg-primary pt-1 pb-[calc(.25rem+env(safe-area-inset-bottom))] pl-[max(.25rem,env(safe-area-inset-left))] pr-[max(.25rem,env(safe-area-inset-right))] lg:hidden" aria-label="{{ __('Mobile quick actions') }}">
            <a href="{{ route('dashboard') }}" class="flex flex-col items-center gap-1 rounded-xl px-2 py-2 text-[10px] font-bold text-secondary hover:bg-secondary"><svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#view-grid"></use></svg><span>{{ __('Home') }}</span></a>
            <a href="{{ route('projects.create') }}" class="flex flex-col items-center gap-1 rounded-xl px-2 py-2 text-[10px] font-bold text-secondary hover:bg-secondary"><svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#cloud-upload"></use></svg><span>{{ __('Create') }}</span></a>
            <button type="button" class="flex flex-col items-center gap-1 rounded-xl px-2 py-2 text-[10px] font-bold text-secondary hover:bg-secondary" @click="palette = true; paletteQuery = ''; paletteIndex = 0; $nextTick(() => $refs.paletteInput.focus())"><svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#code"></use></svg><span>{{ __('Search') }}</span></button>
            <a href="{{ route('notifications.index') }}" class="relative flex flex-col items-center gap-1 rounded-xl px-2 py-2 text-[10px] font-bold text-secondary hover:bg-secondary"><svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#information-circle"></use></svg><span>{{ __('Alerts') }}</span>@if(auth()->user()->unreadNotifications()->exists())<span class="absolute right-3 top-1 h-2 w-2 rounded-full bg-red-500" aria-label="{{ __('Unread alerts') }}"></span>@endif</a>
        </nav>

        <div x-cloak x-show="palette" x-trap.inert.noscroll="palette" class="fixed inset-0 z-[70] flex items-start justify-center bg-slate-950/60 px-4 pt-[10vh]" role="dialog" aria-modal="true" aria-labelledby="command-palette-title" @click.self="palette = false; $nextTick(() => $refs.paletteToggle?.focus())">
            <div class="w-full max-w-xl overflow-hidden rounded-2xl border border-primary bg-primary shadow-2xl" @keydown.arrow-down.prevent="paletteIndex++" @keydown.arrow-up.prevent="paletteIndex = Math.max(0, paletteIndex - 1)">
                <div class="flex items-center justify-between px-4 pt-3"><h2 id="command-palette-title" class="font-bold text-primary">{{ __('Command palette') }}</h2><button type="button" class="button tertiary" aria-label="{{ __('Close command palette') }}" @click="palette = false; $nextTick(() => $refs.paletteToggle?.focus())">×</button></div>
                <form method="GET" action="{{ route('search.index') }}" class="border-b border-primary p-3">
                    <label for="command-palette-query" class="sr-only">{{ __('Search commands and resources') }}</label>
                    <input id="command-palette-query" x-ref="paletteInput" x-model="paletteQuery" name="q" type="search" maxlength="100" autocomplete="off" class="input secondary w-full rounded-xl text-base" placeholder="{{ __('Type a command or resource name…') }}">
                </form>
                <nav class="max-h-[55vh] overflow-y-auto p-2" aria-label="{{ __('Quick actions') }}">
                    @foreach ([
                        [__('Dashboard'), route('dashboard'), __('overview home')],
                        [__('Create application'), route('projects.create'), __('new project app')],
                        [__('Provision server'), route('servers.create'), __('new cloud infrastructure')],
                        [__('Import existing server'), route('servers.import.create'), __('ssh migrate')],
                        [__('Add website'), route('websites.create'), __('domain site')],
                        [__('Connect repository'), route('repositories.create'), __('git source deploy')],
                        [__('View deployments'), route('builds.index'), __('build history releases')],
                        [__('Open live logs'), route('websites.index'), __('runtime logs')],
                        [__('Observability'), route('observability.index'), __('alerts status incidents')],
                        [__('Database operations'), route('databases.index'), __('mysql postgres clone credentials inspect')],
                        [__('High availability'), route('load-balancers.index'), __('load balancer failover nodes traffic')],
                        [__('API and automation'), route('automation.index'), __('tokens schedules workflow')],
                        [__('Product guide'), route('docs'), __('help documentation')],
                    ] as [$label, $url, $keywords])
                        <a href="{{ $url }}" x-show="paletteQuery === '' || {{ Illuminate\Support\Js::from(strtolower($label.' '.$keywords)) }}.includes(paletteQuery.toLowerCase())" class="flex items-center justify-between rounded-xl px-4 py-3 text-sm font-bold text-primary hover:bg-secondary focus:bg-secondary focus:outline-none">
                            <span>{{ $label }}</span><span aria-hidden="true" class="text-secondary">↵</span>
                        </a>
                    @endforeach
                    <div class="border-t border-primary px-4 py-3 text-xs text-secondary">
                        {{ __('Press Enter to search all workspace resources for your exact query.') }}
                    </div>
                </nav>
            </div>
        </div>

        <!--
         ! ------------------------------------------------------------
         ! Footer and links
         ! ------------------------------------------------------------
         !-->
        <div class="w-full bg-primary border-primary text-primary border-t px-8 py-6 lg:flex justify-between items-center text-sm">
            <p class="mb-2 lg:mb-0">
                &copy; {{ now()->year }} {{ config('app.name') }}
            </p>
            <nav class="flex" aria-label="{{ __('Footer navigation') }}">
                <a href="{{ route('dashboard') }}" class="mr-6 hover:text-gray-900">{{ __('Dashboard') }}</a>
                <a href="{{ route('activity.index') }}" class="mr-6 hover:text-gray-900">{{ __('Activity') }}</a>
                <a href="{{ route('account.index') }}" class="hover:text-gray-900">{{ __('Account') }}</a>
            </nav>
        </div>
    </div>
</x-layouts.core>
