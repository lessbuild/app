@php
    $accessRequestUrl = route('core.admin.access-requests.index');
    $navigation = [
        'groups' => [[
            'label' => __('Platform administration'),
            'items' => [[
                'label' => __('Deployer access requests'),
                'href' => $accessRequestUrl,
                'active' => request()->routeIs('core.admin.access-requests.*'),
            ]],
        ]],
    ];
    $quickItems = [
        ['label' => __('Deployer access requests'), 'href' => $accessRequestUrl, 'keywords' => __('platform administration beta applicants review')],
        ['label' => __('Manage workspaces'), 'href' => route('core.workspaces.index'), 'keywords' => __('workspace directory members teams')],
        ['label' => __('Help and API guides'), 'href' => route('core.help'), 'keywords' => __('documentation support reference')],
    ];
@endphp

<x-signal.layouts.core
    :title="__('Deployer access requests')"
    :description="__('Review private-beta demand without exposing applicant details outside platform administration.')"
    product-key="core"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>

    <x-signal.layouts.topbar
        :navigation="$navigation"
        title="{{ __('Deployer access requests') }}"
        product-key="core"
        :brand-url="route('core.workspaces.index')"
        :projects-url="route('core.workspaces.index')"
        :account-user="auth('platform')->user()"
        logout-route="logout"
        :show-notifications="false"
        :show-project-context="false"
        :show-environment-context="false"
        :show-workspace-switcher="false"
        :show-projects-link="false"
    />

    <main id="main-content" tabindex="-1" data-mobile-main data-mobile-content class="ui-layout-gutter mx-auto w-full max-w-content py-7 sm:py-9">
        <x-alerts.flash />
        <x-signal.ui.page-header
            icon="user-add"
            :title="__('Deployer access requests')"
            :description="__('Review and export Deployer access requests from Buildpusher Core.')"
        />

        <x-scenes.admin.access-request-management
            :requests="$requests"
            :status="$status"
            :counts="$counts"
            :editing-request="$editingRequest"
            :review-dialog-id="$reviewDialogId"
            :review-dialog-open="$reviewDialogOpen"
            :route-names="$routeNames"
        />
    </main>

    <x-signal.layouts.command-palette :navigation="$navigation" :extra-items="$quickItems" />
</x-signal.layouts.core>
