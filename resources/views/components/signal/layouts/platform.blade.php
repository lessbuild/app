@props([
    'navigation' => [],
    'title' => null,
    'description' => null,
    'currentWorkspace',
    'workspaces' => [],
    'contextProjects' => [],
    'currentProject' => null,
    'currentEnvironment' => null,
    'environmentContextUnavailable' => false,
    'environmentOptions' => [],
    'showEnvironmentContext' => false,
    'environmentIndexUrl' => null,
    'productUrlOverrides' => null,
    'accountUser' => null,
])

<x-signal.layouts.core :title="$title" :description="$description" product-key="core">
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.layouts.topbar
        :navigation="$navigation"
        :title="$title"
        product-key="core"
        :brand-url="route('core.workspace.dashboard', $currentWorkspace)"
        :projects-url="route('core.projects.index', $currentWorkspace)"
        :current-workspace="$currentWorkspace"
        :workspace-options="$workspaces"
        workspace-switch-route="core.workspaces.select"
        workspace-manage-route="core.workspace.team.index"
        :workspace-manage-parameters="['workspace' => $currentWorkspace]"
        :account-user="$accountUser ?? auth()->user()"
        logout-route="platform.logout"
        :show-notifications="false"
        :current-context="$currentProject"
        :context-options="$contextProjects"
        context-show-route="core.projects.show"
        context-label="{{ __('Project') }}"
        context-index-label="{{ __('All projects') }}"
        context-all-label="{{ __('View all projects') }}"
        :context-index-url="route('core.projects.index', $currentWorkspace)"
        :environment-options="$environmentOptions"
        :current-environment="$currentEnvironment"
        :environment-context-unavailable="$environmentContextUnavailable"
        :environment-index-url="$environmentIndexUrl ?? route('core.projects.index', $currentWorkspace)"
        :product-url-overrides="$productUrlOverrides"
        :show-project-context="true"
        :show-environment-context="$showEnvironmentContext"
    />

    <main id="main-content" tabindex="-1" data-mobile-main data-mobile-content class="mx-auto w-full max-w-screen-2xl px-4 py-7 sm:px-6 sm:py-9 lg:px-8">
        <x-alerts.flash />
        {{ $slot }}
    </main>

    <x-signal.layouts.command-palette
        :navigation="$navigation"
        :search-url="route('core.workspace.search', $currentWorkspace)"
    />
</x-signal.layouts.core>
