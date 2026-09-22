@props([
    'navigation' => [],
    'mobile' => false,
])

<div class="flex h-full flex-col gap-7 px-4 py-5">
    <a href="{{ route('dashboard') }}" data-auth-brand class="flex items-center gap-3 px-2 text-base font-extrabold tracking-tight text-ink" aria-label="{{ config('app.name') }} home">
        <span class="grid h-9 w-9 place-items-center rounded-xl bg-ink text-surface shadow-soft">
            <img src="{{ asset('favicon.svg') }}" alt="" class="h-5 w-5 rounded-md">
        </span>
        <span class="truncate">{{ config('app.name') }}</span>
    </a>

    <div class="rounded-panel border border-line bg-surface-muted p-3">
        <p class="truncate text-xs font-extrabold text-ink">{{ auth()->user()->currentOrganization?->name ?: __('Your workspace') }}</p>
        <p class="mt-1 text-[11px] leading-4 text-muted">{{ __('A focused space for deployments, infrastructure, and recovery.') }}</p>
    </div>

    <a
        href="{{ route('search.index') }}"
        data-workspace-search-trigger
        class="ui-btn ui-btn-secondary ui-btn-sm w-full justify-between"
        @click.prevent="openPalette($event.currentTarget)"
    >
        <span>{{ __('Search or jump to…') }}</span>
        <kbd class="ui-kbd">⌘K</kbd>
    </a>

    <nav class="flex-1" aria-label="{{ __('Application navigation') }}">
        @if ($mobile)
            @foreach ($navigation['mobile']['groups'] ?? [] as $group)
                <section class="{{ $loop->first ? '' : 'mt-5' }}" aria-labelledby="mobile-navigation-group-{{ $loop->index }}">
                    <p id="mobile-navigation-group-{{ $loop->index }}" class="mb-3 px-3 text-[10px] font-extrabold uppercase tracking-[0.18em] text-subtle">
                        {{ $loop->first ? __('Workspace') : __('Settings and support') }}
                    </p>
                    <div class="space-y-1">
                        @foreach ($group as $item)
                            <x-layouts.partials.navigation-link :item="$item" />
                        @endforeach
                    </div>
                </section>
            @endforeach
        @else
            @foreach ($navigation['groups'] ?? [] as $group)
                <section class="{{ $loop->first ? '' : 'mt-5' }}" aria-labelledby="desktop-navigation-group-{{ $loop->index }}">
                    <p id="desktop-navigation-group-{{ $loop->index }}" class="mb-3 px-3 text-[10px] font-extrabold uppercase tracking-[0.18em] text-subtle">
                        {{ $group['label'] }}
                    </p>
                    <div class="space-y-1">
                        @foreach ($group['items'] as $item)
                            <x-layouts.partials.navigation-link :item="$item" />
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif
    </nav>

    <div class="border-t border-line pt-4">
        @unless ($mobile)
            @if (($navigation['support'] ?? []) !== [])
                <section aria-labelledby="navigation-help">
                    <p id="navigation-help" class="mb-3 px-3 text-[10px] font-extrabold uppercase tracking-[0.18em] text-subtle">{{ __('Help') }}</p>
                    <div class="space-y-1">
                        @foreach ($navigation['support'] as $item)
                            <x-layouts.partials.navigation-link :item="$item" />
                        @endforeach
                    </div>
                </section>
            @endif

            @if (($navigation['profile'] ?? []) !== [])
                <section class="mt-5" aria-labelledby="navigation-workspace">
                    <p id="navigation-workspace" class="mb-3 px-3 text-[10px] font-extrabold uppercase tracking-[0.18em] text-subtle">{{ __('Workspace') }}</p>
                    <div class="space-y-1">
                        @foreach ($navigation['profile'] as $item)
                            <x-layouts.partials.navigation-link :item="$item" />
                        @endforeach
                    </div>
                </section>
            @endif
        @endunless

        <div class="mt-4 flex min-w-0 items-center gap-3 rounded-xl bg-surface-muted p-3">
            <x-avatar :name="auth()->user()->name" class="ui-avatar ui-avatar-sm" />
            <div class="min-w-0">
                <p class="truncate text-xs font-bold text-ink">{{ auth()->user()->name }}</p>
                <p class="truncate text-[11px] text-muted">{{ auth()->user()->email }}</p>
            </div>
        </div>

        <form action="{{ route('logout') }}" method="post" class="mt-3">
            @csrf
            <x-ui.button type="submit" variant="ghost" class="w-full justify-start px-3">
                {{ __('Log out') }}
            </x-ui.button>
        </form>
    </div>
</div>
