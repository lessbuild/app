<x-monitor::ui.document :title="html_entity_decode(trim($__env->yieldContent('title', 'Overview')), ENT_QUOTES, 'UTF-8')">
    <a href="#main-content" class="ui-skip-link">Skip to content</a>
    <x-signal.layouts.topbar
        product-key="monitor"
        :navigation="$signalTopbar"
        :brand-url="route('monitor.dashboard')"
        :projects-url="$signalTopbar['projects_url']"
        :current-workspace="$currentWorkspace"
        :workspace-options="$signalTopbar['workspaces']"
        workspace-switch-route="monitor.workspaces.switch"
        workspace-manage-route="monitor.settings.team"
        :account-user="$accountUser"
        logout-route="monitor.logout"
        :show-notifications="false"
        :current-context="$signalTopbarContext"
        :context-options="$signalTopbar['contexts']"
        context-show-route="monitor.applications.show"
        context-label="Application"
        context-index-label="All applications"
        context-all-label="View all applications"
        :context-index-url="route('monitor.applications.index')"
        :current-environment="$signalTopbarEnvironment"
        :environment-options="$signalTopbarEnvironments"
        :environment-index-url="$signalTopbarContext ? route('monitor.applications.show', $signalTopbarContext) : route('monitor.applications.index')"
        :product-url-overrides="$productUrlOverrides ?? null"
        :shared-context-unavailable="$sharedContextUnavailable ?? false"
    />

    <main id="main-content" tabindex="-1" class="ui-layout-gutter mx-auto max-w-content space-y-6 py-8 pb-24 sm:py-10 lg:pb-10">
        <x-monitor::ui.feedback />
        @unless($accountUser->hasVerifiedEmail())
            <x-signal.ui.alert as="div" tone="warning" class="flex-col text-xs sm:flex-row sm:items-center sm:justify-between">
                <p>Verify {{ $accountUser->email }} to invite teammates or accept invitations.</p>
                <a href="{{ route('monitor.verification.notice') }}" class="shrink-0 font-bold underline">Verify email</a>
            </x-signal.ui.alert>
        @endunless
        <x-monitor::ui.usage-banner :summary="$workspaceUsageSummary" :workspace="$currentWorkspace" />
        @yield('content')
    </main>

    <x-monitor::ui.quick-navigation :navigation="$workspaceNavigation" />
    <x-signal.layouts.command-palette
        :navigation="$signalTopbar"
        :search-url="route('monitor.workspace.search', $currentWorkspace)"
    />
</x-monitor::ui.document>
