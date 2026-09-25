@props([
    'title',
    'description' => null,
])

@php
    $adminItems = collect([
        [
            'label' => __('Business analytics'),
            'href' => \Illuminate\Support\Facades\Route::has('core.admin.analytics') ? route('core.admin.analytics') : null,
            'active' => request()->routeIs('core.admin.analytics'),
            'keywords' => __('users workspaces subscriptions deployments revenue'),
        ],
        [
            'label' => __('Deployer access requests'),
            'href' => \Illuminate\Support\Facades\Route::has('core.admin.access-requests.index') ? route('core.admin.access-requests.index') : null,
            'active' => request()->routeIs('core.admin.access-requests.*'),
            'keywords' => __('beta applicants review invitations'),
        ],
    ])->filter(fn (array $item): bool => filled($item['href']))->values();
    $navigation = [
        'groups' => $adminItems->isEmpty() ? [] : [[
            'label' => __('Platform administration'),
            'items' => $adminItems->map(fn (array $item): array => [
                'label' => $item['label'],
                'href' => $item['href'],
                'active' => $item['active'],
            ])->all(),
        ]],
    ];
    $quickItems = $adminItems->map(fn (array $item): array => [
        'label' => $item['label'],
        'href' => $item['href'],
        'keywords' => $item['keywords'],
    ])->all();
    $quickItems[] = [
        'label' => __('Manage workspaces'),
        'href' => route('core.workspaces.index'),
        'keywords' => __('workspace directory teams members'),
    ];
    $quickItems[] = [
        'label' => __('Help and API guides'),
        'href' => route('core.help'),
        'keywords' => __('documentation support reference'),
    ];
@endphp

<x-signal.layouts.core :title="$title" :description="$description" product-key="core">
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>

    <x-signal.layouts.topbar
        :navigation="$navigation"
        :title="$title"
        product-key="core"
        :brand-url="route('core.workspaces.index')"
        :projects-url="route('core.workspaces.index')"
        :account-user="auth('platform')->user()"
        logout-route="logout"
        workspace-manage-route="core.workspaces.index"
        :show-notifications="false"
        :show-project-context="false"
        :show-environment-context="false"
        :show-workspace-switcher="false"
        :show-projects-link="false"
    />

    <main id="main-content" tabindex="-1" data-mobile-main data-mobile-content class="ui-layout-gutter mx-auto w-full max-w-content py-7 sm:py-9">
        <x-alerts.flash />
        {{ $slot }}
    </main>

    <x-signal.layouts.command-palette :navigation="$navigation" :extra-items="$quickItems" />
</x-signal.layouts.core>
