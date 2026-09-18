@props([
    'title' => null,
    'description' => null,
])

@php($resolvedTitle = $title ?: app(\App\View\PageTitle::class)->for(request()->route()))

<x-layouts.core :title="$resolvedTitle" :description="$description">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-lg focus:bg-primary focus:px-4 focus:py-3 focus:font-semibold focus:text-primary focus:shadow-xl">
        {{ __('Skip to main content') }}
    </a>
    <div
        class="flex flex-wrap overflow-x-hidden pb-[calc(4.5rem+env(safe-area-inset-bottom))] lg:pb-0"
        x-data="{ menu: false, palette: false, paletteQuery: '', paletteIndex: -1, paletteLinks() { return [...(this.$refs.paletteResults?.querySelectorAll('[data-palette-item]') ?? [])].filter((element) => element.offsetParent !== null); }, movePalette(delta) { const links = this.paletteLinks(); if (!links.length) { this.paletteIndex = -1; this.$refs.paletteInput.focus(); return; } if (this.paletteIndex < 0) { this.paletteIndex = delta > 0 ? 0 : links.length - 1; } else { this.paletteIndex = (this.paletteIndex + delta + links.length) % links.length; } links[this.paletteIndex]?.focus(); }, movePaletteTo(index) { const links = this.paletteLinks(); if (!links.length) { this.paletteIndex = -1; this.$refs.paletteInput.focus(); return; } this.paletteIndex = Math.min(Math.max(index, 0), links.length - 1); links[this.paletteIndex]?.focus(); }, resetPaletteSelection() { this.paletteIndex = -1; }, restorePaletteFocus() { this.$nextTick(() => { const trigger = [this.$refs.paletteToggle, this.$refs.mobilePaletteToggle, this.$refs.mobileQuickPaletteToggle].find((element) => element && element.offsetParent !== null); trigger?.focus(); }) } }"
        @keydown.escape.window="if (palette) { palette = false; restorePaletteFocus() } else if (menu) { menu = false; $nextTick(() => $refs.navigationToggle.focus()) }"
        @keydown.window.prevent.cmd.k="palette = true; paletteQuery = ''; paletteIndex = -1; $nextTick(() => $refs.paletteInput.focus())"
        @keydown.window.prevent.ctrl.k="palette = true; paletteQuery = ''; paletteIndex = -1; $nextTick(() => $refs.paletteInput.focus())"
    >

        <!--
         ! ------------------------------------------------------------
         ! Load the navigation
         ! ------------------------------------------------------------
         !-->
        <button
            type="button"
            class="fixed inset-0 z-40 bg-black/40 lg:hidden"
            style="display: none"
            x-show="menu"
            aria-label="{{ __('Close navigation') }}"
            @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())"
        ></button>
        <x-layouts.sidebar :navigation="$navigation ?? []" />
        <x-layouts.mobile-navigation :navigation="$navigation ?? []" />

        <!--
         ! ------------------------------------------------------------
         ! Website main content
         ! ------------------------------------------------------------
         !-->
        <main id="main-content" tabindex="-1" class="min-w-0 w-full bg-secondary pl-0 lg:pl-64 min-h-screen">
            <div class="sticky top-0 z-30 bg-gray-800 text-gray-100 border-b border-primary shadow-xs">
                <div class="flex h-16 items-center justify-between px-4 lg:hidden">
                    <a href="{{ route('dashboard') }}" data-auth-brand class="text-lg font-bold text-gray-100">{{ config('app.name') }}</a>
                    <button type="button" x-ref="mobilePaletteToggle" class="button secondary hidden min-h-[44px] sm:inline-flex" aria-label="{{ __('Search and navigate') }}" @click="palette = true; paletteQuery = ''; paletteIndex = -1; $nextTick(() => $refs.paletteInput.focus())"><span>{{ __('Search and navigate') }}</span><kbd class="ml-2 rounded-md border border-secondary px-1.5 py-0.5 text-[10px] text-secondary">Ctrl K</kbd></button>
                    <button type="button" x-ref="navigationToggle" class="button secondary flex min-h-[44px] gap-2" aria-controls="primary-navigation" :aria-expanded="menu.toString()" aria-label="{{ __('Toggle navigation') }}" @click="menu = true; $nextTick(() => $refs.closeNavigation.focus())"><svg class="h-4 w-4 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#menu"></use></svg>{{ __('Menu') }}</button>
                </div>
                <div class="hidden h-14 w-full items-center justify-between border-b border-primary px-6 lg:flex">
                    <div class="flex items-center gap-3">
                        <div class="hidden sm:block">
                            <button type="button" x-ref="paletteToggle" class="button secondary" @click="palette = true; paletteQuery = ''; paletteIndex = -1; $nextTick(() => $refs.paletteInput.focus())"><span>{{ __('Search and navigate') }}</span><kbd class="ml-3 rounded-md border border-secondary px-1.5 py-0.5 text-[10px] text-secondary">⌘K</kbd></button>
                        </div>
                    </div>
                    <div class="relative flex items-center">
                        <a href="{{ route('account.index') }}" aria-label="{{ __('Account settings') }}">
                            <x-avatar :name="auth()->user()->name" class="h-8 w-8 rounded-lg text-[10px] shadow-lg" />
                        </a>

                        <form action="{{ route('logout') }}" method="post" class="ml-4">
                            @csrf
                            <x-ui.button type="submit" variant="ghost">{{ __('Logout') }}</x-ui.button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="mb-20 p-4 sm:p-6">
                <x-alerts.flash />
                {{ $slot }}
            </div>
        </main>

        <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 overflow-hidden border-t border-primary bg-primary pt-1 pb-[calc(.25rem+env(safe-area-inset-bottom))] pl-[max(.25rem,env(safe-area-inset-left))] pr-[max(.25rem,env(safe-area-inset-right))] lg:hidden" aria-label="{{ __('Mobile quick actions') }}">
            <a href="{{ route('dashboard') }}" data-mobile-quick-action="home" @class(['flex min-h-[44px] flex-col items-center gap-1 rounded-xl px-2 py-2 text-[10px] font-bold hover:bg-secondary', 'text-ternary' => request()->routeIs('dashboard'), 'text-secondary' => ! request()->routeIs('dashboard')]) @if(request()->routeIs('dashboard')) aria-current="page" @endif><svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#view-grid"></use></svg><span>{{ __('Home') }}</span></a>
            <a href="{{ route('projects.create') }}" data-mobile-quick-action="create" @class(['flex min-h-[44px] flex-col items-center gap-1 rounded-xl px-2 py-2 text-[10px] font-bold hover:bg-secondary', 'text-ternary' => request()->routeIs('projects.create'), 'text-secondary' => ! request()->routeIs('projects.create')]) @if(request()->routeIs('projects.create')) aria-current="page" @endif><svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#cloud-upload"></use></svg><span>{{ __('New app') }}</span></a>
            <button type="button" data-mobile-quick-action="search" x-ref="mobileQuickPaletteToggle" class="flex min-h-[44px] flex-col items-center gap-1 rounded-xl px-2 py-2 text-[10px] font-bold text-secondary hover:bg-secondary" @click="palette = true; paletteQuery = ''; paletteIndex = -1; $nextTick(() => $refs.paletteInput.focus())"><svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#code"></use></svg><span>{{ __('Search') }}</span></button>
            <a href="{{ route('notifications.index') }}" data-mobile-quick-action="alerts" @class(['relative flex min-h-[44px] flex-col items-center gap-1 rounded-xl px-2 py-2 text-[10px] font-bold hover:bg-secondary', 'text-ternary' => request()->routeIs('notifications.*'), 'text-secondary' => ! request()->routeIs('notifications.*')]) @if(request()->routeIs('notifications.*')) aria-current="page" @endif><svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#information-circle"></use></svg><span>{{ __('Alerts') }}</span>@if(($navigation['unread_notifications'] ?? 0) > 0)<span class="absolute right-3 top-1 h-2 w-2 rounded-full bg-red-500" aria-label="{{ __('Unread alerts') }}"></span>@endif</a>
        </nav>

        <div x-cloak x-show="palette" x-trap.inert.noscroll="palette" class="fixed inset-0 z-[70] flex items-start justify-center bg-slate-950/60 px-4 pt-[10vh]" role="dialog" aria-modal="true" aria-labelledby="command-palette-title" @click.self="palette = false; restorePaletteFocus()">
            <div class="w-full max-w-xl overflow-hidden rounded-2xl border border-primary bg-primary shadow-2xl" @keydown.arrow-down.prevent="movePalette(1)" @keydown.arrow-up.prevent="movePalette(-1)" @keydown.home.prevent="movePaletteTo(0)" @keydown.end.prevent="movePaletteTo(paletteLinks().length - 1)">
                <div class="flex items-center justify-between px-4 pt-3"><h2 id="command-palette-title" class="font-bold text-primary">{{ __('Command palette') }}</h2><x-ui.button type="button" variant="ghost" class="min-h-10 px-2 text-lg" aria-label="{{ __('Close command palette') }}" @click="palette = false; restorePaletteFocus()">×</x-ui.button></div>
                <form method="GET" action="{{ route('search.index') }}" class="border-b border-primary p-3">
                    <label for="command-palette-query" class="sr-only">{{ __('Search commands and resources') }}</label>
                    <input id="command-palette-query" x-ref="paletteInput" x-model="paletteQuery" @input="resetPaletteSelection()" name="q" type="search" maxlength="100" autocomplete="off" class="input secondary w-full rounded-xl text-base" placeholder="{{ __('Type a command or resource name…') }}">
                </form>
                <nav x-ref="paletteResults" class="max-h-[55vh] overflow-y-auto p-2" aria-label="{{ __('Quick actions') }}" role="listbox">
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
                        <a id="command-palette-result-{{ $loop->index }}" href="{{ $url }}" x-show="paletteQuery === '' || {{ Illuminate\Support\Js::from(strtolower($label.' '.$keywords)) }}.includes(paletteQuery.toLowerCase())" data-palette-item role="option" :aria-selected="paletteLinks()[paletteIndex] === $el ? 'true' : 'false'" class="flex items-center justify-between rounded-xl px-4 py-3 text-sm font-bold text-primary hover:bg-secondary focus:bg-secondary focus:outline-hidden">
                            <span>{{ $label }}</span><span aria-hidden="true" class="text-secondary">↵</span>
                        </a>
                    @endforeach
                    <p x-show="paletteQuery !== '' && paletteLinks().length === 0" role="status" class="px-4 py-3 text-sm text-secondary">
                        {{ __('No matching quick actions. Press Enter to search all workspace resources.') }}
                    </p>
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
        <div class="flex w-full items-center justify-between border-t border-primary bg-primary px-6 py-6 text-sm text-primary sm:px-8 lg:flex">
            <p class="mb-2 lg:mb-0">
                &copy; {{ now()->year }} {{ config('app.name') }}
            </p>
            <nav class="flex" aria-label="{{ __('Footer navigation') }}">
                <a href="{{ route('dashboard') }}" class="mr-6 hover:text-ternary">{{ __('Dashboard') }}</a>
                <a href="{{ route('activity.index') }}" class="mr-6 hover:text-ternary">{{ __('Activity') }}</a>
                <a href="{{ route('account.index') }}" class="hover:text-ternary">{{ __('Account') }}</a>
            </nav>
        </div>
    </div>
</x-layouts.core>
