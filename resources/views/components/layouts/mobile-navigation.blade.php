@props(['navigation' => []])

<section
    id="primary-navigation"
    x-cloak
    x-show="menu"
    x-trap.inert.noscroll="menu"
    @resize.window="if (window.innerWidth >= 1024) menu = false"
    class="fixed inset-0 z-[60] flex h-[100dvh] flex-col bg-primary lg:hidden"
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('Primary navigation') }}"
>
    <div class="flex shrink-0 items-center gap-3 border-b border-gray-700 bg-gray-800 px-4 py-3 text-gray-100 shadow-xs">
        <a href="{{ route('dashboard') }}" class="max-w-[35%] truncate text-sm font-bold text-white">{{ config('app.name') }}</a>
        <form method="GET" action="{{ route('search.index') }}" class="min-w-0 flex-1">
            <label for="mobile-workspace-search" class="sr-only">{{ __('Search workspace') }}</label>
            <input id="mobile-workspace-search" type="search" name="q" maxlength="100" placeholder="{{ __('Search workspace') }}" class="h-11 w-full rounded-lg border border-gray-600 bg-gray-700 px-3 text-sm text-gray-100 placeholder-gray-300">
        </form>
        <button type="button" x-ref="closeNavigation" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-2xl text-white hover:bg-gray-700" aria-label="{{ __('Close navigation') }}" @click="menu = false; $nextTick(() => $refs.navigationToggle.focus())">×</button>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
        <a href="{{ route('organizations.index') }}" class="mb-4 flex items-center gap-3 rounded-xl border border-primary p-3 shadow-xs">
            <x-avatar :name="auth()->user()->currentOrganization?->name ?: config('app.name')" class="h-10 w-10 shrink-0 rounded-lg" />
            <span class="min-w-0">
                <span class="block text-[10px] font-bold uppercase tracking-wide text-secondary">{{ __('Current workspace') }}</span>
                <span class="block truncate text-sm font-bold text-primary">{{ auth()->user()->currentOrganization?->name ?: config('app.name') }}</span>
            </span>
        </a>

        <div class="space-y-2">
            @foreach ($navigation['groups'] ?? [] as $group)
                @php($groupActive = collect($group['items'])->contains(fn (array $item): bool => ($item['active'] ?? []) !== [] && request()->routeIs(...$item['active'])) )
                <details class="overflow-hidden rounded-xl border border-primary bg-primary" @if ($group['mobile_expanded'] || $groupActive) open @endif>
                    <summary class="flex min-h-[46px] cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-primary [&::-webkit-details-marker]:hidden">
                        <span>{{ $group['label'] }}</span>
                        <svg class="ui-nav-chevron h-4 w-4 shrink-0 stroke-2 text-secondary" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
                    </summary>
                    <nav class="grid grid-cols-2 gap-2 border-t border-primary p-2" aria-label="{{ $group['label'] }}">
                        @foreach ($group['items'] as $item)
                            <x-layouts.partials.navigation-link :item="$item" mobile />
                        @endforeach
                    </nav>
                </details>
            @endforeach
        </div>

        <details class="mt-2 overflow-hidden rounded-xl border border-primary bg-primary" open>
            <summary class="flex min-h-[46px] cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-primary [&::-webkit-details-marker]:hidden">
                <span>{{ __('Help') }}</span>
                <svg class="ui-nav-chevron h-4 w-4 shrink-0 stroke-2 text-secondary" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
            </summary>
            <nav class="grid grid-cols-2 gap-2 border-t border-primary p-2" aria-label="{{ __('Help') }}">
                @foreach ($navigation['support'] ?? [] as $item)
                    <x-layouts.partials.navigation-link :item="$item" mobile />
                @endforeach
            </nav>
        </details>

        <details class="mt-2 overflow-hidden rounded-xl border border-primary bg-primary" open>
            <summary class="flex min-h-[46px] cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-primary [&::-webkit-details-marker]:hidden">
                <span>{{ __('Workspace') }}</span>
                <svg class="ui-nav-chevron h-4 w-4 shrink-0 stroke-2 text-secondary" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#chevron-right"></use></svg>
            </summary>
            <nav class="grid grid-cols-2 gap-2 border-t border-primary p-2" aria-label="{{ __('Workspace') }}">
                @foreach ($navigation['profile'] ?? [] as $item)
                    <x-layouts.partials.navigation-link :item="$item" mobile />
                @endforeach
            </nav>
        </details>

        <form method="POST" action="{{ route('logout') }}" class="mt-4">
            @csrf
            <button type="submit" class="button secondary w-full min-h-[44px]">{{ __('Logout') }}</button>
        </form>
    </div>
</section>
