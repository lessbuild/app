@props([
    'createUrl',
    'createOpen' => false,
])

<nav
    data-mobile-quick-navigation
    class="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 items-end border-t border-line bg-surface/95 px-2 pt-2 pb-[env(safe-area-inset-bottom,0px)] shadow-soft backdrop-blur lg:hidden"
    aria-label="{{ __('Quick navigation') }}"
>
    <x-signal.ui.link href="{{ route('dashboard') }}" variant="muted" size="none" layout="stack" class="min-h-14 justify-center gap-1 px-2 text-[10px]">
        <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#cloud-upload"></use></svg>
        <span>{{ __('Home') }}</span>
    </x-signal.ui.link>
    <x-signal.ui.link href="{{ route('projects.index') }}" variant="muted" size="none" layout="stack" class="min-h-14 justify-center gap-1 px-2 text-[10px]">
        <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#view-grid"></use></svg>
        <span>{{ __('Projects') }}</span>
    </x-signal.ui.link>
    <x-signal.ui.button
        :href="$createUrl"
        variant="primary"
        class="mx-2 -mt-3 min-h-14 flex-col gap-1 rounded-panel px-2 py-2 text-[10px] shadow-soft"
        data-mobile-quick-action="create"
        data-modal-trigger="application-create-dialog"
        aria-controls="application-create-dialog"
        :aria-expanded="$createOpen ? 'true' : 'false'"
    >
        <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#plus"></use></svg>
        <span>{{ __('Create') }}</span>
    </x-signal.ui.button>
    <x-signal.ui.link href="{{ route('activity.index') }}" variant="muted" size="none" layout="stack" class="min-h-14 justify-center gap-1 px-2 text-[10px]">
        <svg class="h-5 w-5 stroke-2" aria-hidden="true"><use xlink:href="/assets/images/icons.svg#clock"></use></svg>
        <span>{{ __('Activity') }}</span>
    </x-signal.ui.link>
</nav>
