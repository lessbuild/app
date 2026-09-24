@props([
    'id',
    'title',
    'brandUrl' => null,
    'brandLabel' => null,
    'breakpoint' => 1024,
    'desktopNavigation' => '#signal-product-navigation',
])

@php($brandUrl ??= url('/'))
@php($brandLabel ??= config('app.name'))

<div
    id="{{ $id }}"
    class="app-mobile-sidebar fixed inset-0 z-50 hidden lg:hidden"
    data-mobile-drawer
    data-mobile-breakpoint="{{ $breakpoint }}"
    data-desktop-navigation="{{ $desktopNavigation }}"
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    aria-labelledby="{{ $id }}-title"
>
    <button
        type="button"
        class="app-mobile-sidebar-backdrop absolute inset-0"
        data-mobile-toggle
        aria-controls="{{ $id }}"
        aria-label="{{ __('Close navigation') }}"
        tabindex="-1"
    ></button>

    <aside class="app-mobile-sidebar-panel relative mr-auto flex h-full w-[min(var(--sidebar-width),88vw)] flex-col gap-5 overflow-y-auto border-r border-line bg-surface px-4 py-5 shadow-2xl">
        <div class="flex items-center justify-between gap-3">
            <a href="{{ $brandUrl }}" class="flex min-w-0 items-center gap-3 text-sm font-extrabold tracking-tight text-ink">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-ink text-surface">
                    <img src="{{ asset('favicon.svg') }}" alt="" class="h-5 w-5 rounded-md">
                </span>
                <span class="truncate">{{ $brandLabel }}</span>
            </a>
            <button
                type="button"
                class="ui-icon-btn shrink-0"
                data-mobile-toggle
                aria-controls="{{ $id }}"
                aria-expanded="false"
                aria-label="{{ __('Close navigation') }}"
            >
                <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#close"></use></svg>
            </button>
        </div>

        <h2 id="{{ $id }}-title" class="sr-only">{{ $title }}</h2>
        <div class="min-h-0 flex-1">{{ $slot }}</div>
    </aside>
</div>
