<x-signal.layouts.platform
    :title="__('Analytics sites')"
    :description="__('Manage mapped Analytics sites, verification, privacy settings, goals, and reporting.')"
    :navigation="$navigation"
    :account-user="$accountUser"
    :current-workspace="$currentWorkspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name"
        :title="__('Analytics sites')"
        :description="__('Manage Analytics site setup and reports from your shared workspace.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.analytics.data.index', $workspace)" variant="secondary">{{ __('Data and processing') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="secondary">{{ __('Workspace management') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (! $snapshot->available)
        <x-signal.ui.alert class="mt-6" tone="warning" role="status">{{ __('Analytics site data is temporarily unavailable. Try again later.') }}</x-signal.ui.alert>
    @else
        @if (! $snapshot->planAvailable)
            <x-signal.ui.alert class="mt-6" tone="warning" role="status">{{ __('Analytics could not confirm the current plan. New sites and plan retention details are unavailable until billing access responds.') }}</x-signal.ui.alert>
        @endif
        @if ($snapshot->truncated)
            <x-signal.ui.alert class="mt-5" tone="info" role="status">{{ __('The list is bounded for display. Search or filter Analytics sites in the Analytics application to open additional sites.') }}</x-signal.ui.alert>
        @endif

        @if ($snapshot->canManageSites)
            <x-signal.ui.card as="form" method="POST" :action="route('core.workspace.analytics.sites.create', $workspace)" class="mt-6 grid gap-4 p-5 sm:grid-cols-2">
                @csrf
                <div class="sm:col-span-2">
                    <h2 class="text-lg font-extrabold text-ink">{{ __('Add a site') }}</h2>
                    <p class="mt-1 text-sm text-muted">
                        @if ($snapshot->planName)
                            {{ __('Plan: :plan · :count sites in this Analytics workspace.', ['plan' => $snapshot->planName, 'count' => $snapshot->siteCount]) }}
                        @else
                            {{ __('Create a site in the mapped Analytics workspace. Shared project linking remains an explicit resource-link step.') }}
                        @endif
                    </p>
                </div>
                <x-signal.ui.input-field name="name" :label="__('Site name')" :value="old('name')" required />
                <x-signal.ui.input-field name="domain" :label="__('Primary domain')" :value="old('domain')" placeholder="example.com" required />
                <x-signal.ui.input-field name="timezone" :label="__('Reporting timezone')" :value="old('timezone', 'UTC')" required />
                <div class="flex items-end justify-end">
                    <x-signal.ui.button type="submit" variant="primary" :disabled="! $snapshot->canCreateSite">{{ __('Create site') }}</x-signal.ui.button>
                </div>
                @if (! $snapshot->canCreateSite)
                    <p class="text-sm text-muted sm:col-span-2">{{ __('Site creation requires Analytics site-management access and a confirmed plan allowance.') }}</p>
                @endif
            </x-signal.ui.card>
        @endif

        <section class="mt-8" aria-labelledby="analytics-sites-heading">
            <div class="mb-4 flex items-end justify-between gap-4">
                <div><p class="ui-eyebrow">{{ __('Product-owned resources') }}</p><h2 id="analytics-sites-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Sites') }}</h2></div>
                @if ($snapshot->siteLimit !== null)<span class="text-sm text-muted">{{ __('Plan limit: :limit', ['limit' => $snapshot->siteLimit]) }}</span>@endif
            </div>
            @if ($snapshot->sites === [])
                <x-signal.ui.empty-state :title="__('No Analytics sites are available')" :description="__('Sites appear here when your Analytics workspace role and current shared project access allow them.')" icon="chart-bar" />
            @else
                <x-signal.ui.table :caption="__('Analytics sites')">
                    <x-slot:head><tr><th scope="col">{{ __('Site') }}</th><th scope="col">{{ __('Collection') }}</th><th scope="col">{{ __('Project link') }}</th><th scope="col">{{ __('Last received') }}</th><th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th></tr></x-slot:head>
                    @foreach ($snapshot->sites as $site)
                        <tr>
                            <th scope="row"><div class="font-bold text-ink">{{ $site->name }}</div><div class="mt-1 text-xs text-muted">{{ implode(', ', $site->domains) }}</div><div class="mt-1"><x-signal.ui.badge :tone="$site->verified ? 'success' : 'warning'">{{ $site->verified ? __('Verified') : __('Verification needed') }}</x-signal.ui.badge></div></th>
                            <td><x-signal.ui.badge :tone="$site->collectionAvailable ? 'success' : 'neutral'">{{ $site->collectionAvailable ? __('Collecting') : __('Paused or unverified') }}</x-signal.ui.badge></td>
                            <td>{{ $site->linkedToSharedProject ? __('Linked') : __('Not linked to a shared project') }}</td>
                            <td>{{ $site->lastEventAt?->diffForHumans() ?? __('No events yet') }}</td>
                            <td><div class="flex flex-wrap justify-end gap-2">
                                <x-signal.ui.button :href="route('core.workspace.analytics.sites.setup', [$workspace, $site->id])" variant="secondary" class="ui-btn-sm">{{ __('Setup') }}</x-signal.ui.button>
                                <x-signal.ui.button :href="route('core.workspace.analytics.goals.index', [$workspace, $site->id])" variant="secondary" class="ui-btn-sm">{{ __('Goals') }}</x-signal.ui.button>
                                @if ($site->canManage)<x-signal.ui.button :href="route('core.workspace.analytics.sites.settings', [$workspace, $site->id])" variant="secondary" class="ui-btn-sm">{{ __('Settings') }}</x-signal.ui.button>@endif
                            </div></td>
                        </tr>
                    @endforeach
                </x-signal.ui.table>
            @endif
        </section>

        @if ($snapshot->planAvailable)
            <x-signal.ui.card class="mt-6 p-5">
                <h2 class="font-extrabold text-ink">{{ __('Effective data retention') }}</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('Retention follows the current Analytics workspace plan. These values are read-only here.') }}</p>
                <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div><dt class="text-xs text-muted">{{ __('Event and visit detail') }}</dt><dd class="font-bold">{{ $snapshot->detailRetentionDays ? trans_choice(':count day|:count days', $snapshot->detailRetentionDays) : __('Unlimited') }}</dd></div>
                    <div><dt class="text-xs text-muted">{{ __('Daily report aggregates') }}</dt><dd class="font-bold">{{ $snapshot->aggregateRetentionMonths ? trans_choice(':count month|:count months', $snapshot->aggregateRetentionMonths) : __('Unlimited') }}</dd></div>
                    <div><dt class="text-xs text-muted">{{ __('Report download') }}</dt><dd class="font-bold">{{ $snapshot->exportRetentionHours ? trans_choice(':count hour|:count hours', $snapshot->exportRetentionHours) : __('Plan-defined') }}</dd></div>
                </dl>
            </x-signal.ui.card>
        @endif
    @endif
</x-signal.layouts.platform>
