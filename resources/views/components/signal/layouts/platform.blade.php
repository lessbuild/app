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

@php
    $searchAccount = $accountUser instanceof \App\Core\Models\PlatformUser
        ? $accountUser
        : auth('platform')->user();
    $searchActions = [];

    if ($currentWorkspace) {
        $searchActions = [
            ['label' => __('Workspace overview'), 'href' => route('core.workspace.dashboard', $currentWorkspace), 'keywords' => 'projects apps activity summary'],
            ['label' => __('Projects'), 'href' => route('core.projects.index', $currentWorkspace), 'keywords' => 'shared resources environments connections'],
            ['label' => __('Team access'), 'href' => route('core.workspace.team.index', $currentWorkspace), 'keywords' => 'members roles invitations grants seats'],
            ['label' => __('Plans and billing'), 'href' => route('core.workspace.subscriptions', $currentWorkspace), 'keywords' => 'subscriptions usage renewal seats payments'],
            ['label' => __('Workspace management'), 'href' => route('core.workspace.admin', $currentWorkspace), 'keywords' => 'settings administration apps tools'],
            ['label' => __('Workflow activity'), 'href' => route('core.workspace.workflows', $currentWorkspace), 'keywords' => 'tasks deployments exports jobs progress retries'],
            ['label' => __('Delivery history'), 'href' => route('core.workspace.deliveries', $currentWorkspace), 'keywords' => 'webhooks integrations callbacks retries'],
            ['label' => __('API credentials'), 'href' => route('core.workspace.credentials', $currentWorkspace), 'keywords' => 'tokens keys ingestion secrets'],
            ['label' => __('Notifications'), 'href' => route('core.workspace.notifications', $currentWorkspace), 'keywords' => 'inbox alerts incidents messages'],
            ['label' => __('Costs and budgets'), 'href' => route('core.workspace.costs', $currentWorkspace), 'keywords' => 'usage spending infrastructure estimates'],
            ['label' => __('Feedback'), 'href' => route('core.workspace.feedback.index', $currentWorkspace), 'keywords' => 'send review product suggestions'],
            ['label' => __('Help and API guides'), 'href' => route('core.help'), 'keywords' => 'documentation support openapi reference'],
        ];

        if ($searchAccount instanceof \App\Core\Models\PlatformUser) {
            $searchActions = [
                ...$searchActions,
                ...app(\App\Core\Services\WorkspaceAdministrationCatalog::class)->searchItems($searchAccount, $currentWorkspace),
            ];
        }
    }
@endphp

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
        workspace-manage-route="core.workspaces.index"
        :account-user="$accountUser ?? auth()->user()"
        logout-route="platform.logout"
        :notifications-url="route('core.workspace.notifications', $currentWorkspace)"
        :show-notifications="true"
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

    <main id="main-content" tabindex="-1" data-mobile-main data-mobile-content class="ui-layout-gutter mx-auto w-full max-w-content py-7 sm:py-9">
        <x-alerts.flash />
        {{ $slot }}
    </main>

    <x-signal.layouts.command-palette
        :navigation="$navigation"
        :search-url="route('core.workspace.search', $currentWorkspace)"
        :extra-items="$searchActions"
    />
</x-signal.layouts.core>
