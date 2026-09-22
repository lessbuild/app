@props(['navigation' => []])

<aside
    id="desktop-navigation"
    x-cloak
    role="navigation"
    aria-label="{{ __('Primary navigation') }}"
    class="hidden w-[var(--sidebar-width)] shrink-0 border-r border-line bg-surface lg:flex lg:flex-col"
    @click="if ($event.target.closest('a')) menu = false"
>
    <x-layouts.navigation-content :navigation="$navigation" />
</aside>
