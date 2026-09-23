<x-signal.layouts.core :title="$title ?? 'Analytics'" product-key="analytics">
    <x-signal.layouts.topbar
        product-key="analytics"
        :navigation="$signalTopbar"
        :brand-url="route('analytics.dashboard')"
        :projects-url="$signalTopbar['projects_url']"
        :current-workspace="$currentWorkspace"
        :workspace-options="$workspaceOptions"
        workspace-switch-route="analytics.workspaces.select"
        workspace-manage-route="analytics.workspaces.index"
        :account-user="$accountUser"
        logout-route="analytics.logout"
        :show-notifications="false"
        :current-context="$currentAnalyticsSite"
        :context-options="$signalTopbar['contexts']"
        context-label="Site"
        context-index-label="All sites"
        context-all-label="View all sites"
        :context-index-url="route('analytics.dashboard')"
        :show-environment-context="false"
    />

    <main id="main-content" tabindex="-1" class="mx-auto max-w-screen-2xl space-y-6 px-5 py-8 pb-24 sm:px-8 sm:py-10 lg:pb-10">
        @yield('content'){{ $slot ?? '' }}
    </main>

    <x-signal.layouts.command-palette :navigation="$signalTopbar" />
</x-signal.layouts.core>
