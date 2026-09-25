<x-signal.layouts.platform
    :title="__('Analytics site settings')"
    :description="__('Control Analytics collection, domains, and privacy exclusions.')"
    :navigation="$navigation"
    :account-user="$accountUser"
    :current-workspace="$currentWorkspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="$siteData->name" :description="__('Manage collection, verified domains, privacy exclusions, and reporting timezone.')">
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.analytics.sites.setup', [$workspace, $siteData->id])" variant="secondary">{{ __('Setup') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.analytics.sites.index', $workspace)" variant="secondary">{{ __('All sites') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>
    @if (session('status'))<x-signal.ui.alert class="mt-5" tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>@endif
    @if ($errors->any())<x-signal.ui.alert class="mt-5" tone="danger" role="alert"><ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-signal.ui.alert>@endif

    <x-signal.ui.card as="form" method="POST" :action="route('core.workspace.analytics.sites.update', [$workspace, $siteData->id])" class="mt-6 space-y-5 p-5">
        @csrf @method('PUT')
        <x-signal.ui.input-field name="name" :label="__('Site name')" :value="old('name', $siteData->name)" required />
        <x-signal.ui.textarea-field name="domains" :label="__('Allowed domains')" :value="old('domains', implode("\n", $siteData->domains))" :description="__('One exact hostname per line. Changing the list resets verification until DNS is checked again.')" required />
        <x-signal.ui.input-field name="timezone" :label="__('Reporting timezone')" :value="old('timezone', $siteData->timezone)" :description="__('The timezone cannot change after Analytics has received events.')" required />
        <x-signal.ui.textarea-field name="excluded_paths" :label="__('Excluded paths')" :value="old('excluded_paths', implode("\n", $siteData->excludedPaths ?? []))" :description="__('One path or wildcard per line. Matching events are dropped before storage.')" />
        <div class="grid gap-3 sm:grid-cols-2">
            <x-signal.ui.choice id="collection_enabled" name="collection_enabled" :label="__('Accept new collection requests')" :description="__('Turn this off to reject new tracker events.')" :checked="old('collection_enabled', $siteData->collectionEnabled)" unchecked-value="0" card />
            <x-signal.ui.choice id="collection_paused" name="collection_paused" :label="__('Pause collection temporarily')" :description="__('Pause collection without changing domain verification.')" :checked="old('collection_paused', $siteData->collectionPaused)" unchecked-value="0" card />
        </div>
        <div class="flex justify-end"><x-signal.ui.button type="submit" variant="primary">{{ __('Save settings') }}</x-signal.ui.button></div>
    </x-signal.ui.card>

    @if ($snapshot->planAvailable)
        <x-signal.ui.card class="mt-5 p-5"><h2 class="font-extrabold">{{ __('Plan retention') }}</h2><p class="mt-2 text-sm text-muted">{{ __('Analytics applies retention from the current plan. These values cannot be changed from this site form.') }}</p><dl class="mt-3 grid gap-3 sm:grid-cols-3"><div><dt class="text-xs text-muted">{{ __('Event detail') }}</dt><dd>{{ $snapshot->detailRetentionDays ? trans_choice(':count day|:count days', $snapshot->detailRetentionDays) : __('Unlimited') }}</dd></div><div><dt class="text-xs text-muted">{{ __('Aggregate reports') }}</dt><dd>{{ $snapshot->aggregateRetentionMonths ? trans_choice(':count month|:count months', $snapshot->aggregateRetentionMonths) : __('Unlimited') }}</dd></div><div><dt class="text-xs text-muted">{{ __('CSV downloads') }}</dt><dd>{{ $snapshot->exportRetentionHours ? trans_choice(':count hour|:count hours', $snapshot->exportRetentionHours) : __('Plan-defined') }}</dd></div></dl></x-signal.ui.card>
    @endif

    @if ($siteData->canDeleteSite)
        <x-signal.ui.card class="mt-5 border-danger/20 p-5">
            <p class="text-xs font-bold uppercase tracking-wide text-danger">{{ __('Danger zone') }}</p>
            <h2 class="mt-2 font-extrabold">{{ __('Delete this Analytics site') }}</h2>
            <p class="mt-2 text-sm text-muted">{{ __('Collection stops immediately. Analytics removes stored site data and private export files after fencing new work.') }}</p>
            <form class="mt-4 space-y-4" method="POST" action="{{ route('core.workspace.analytics.sites.delete', [$workspace, $siteData->id]) }}">
                @csrf @method('DELETE')
                <x-signal.ui.input-field name="confirmation" :label="__('Type the site slug to confirm')" :value="old('confirmation')" :placeholder="$siteData->deleteConfirmation" required />
                <div class="flex justify-end"><x-signal.ui.button type="submit" variant="danger">{{ __('Delete Analytics site') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.card>
    @endif

    @if ($snapshot->canRequestReport)
        <x-signal.ui.card as="form" method="POST" :action="route('core.workspace.analytics.reports.create', [$workspace, $siteData->id])" class="mt-5 grid gap-4 p-5 sm:grid-cols-2">
            @csrf
            <div class="sm:col-span-2"><h2 class="font-extrabold">{{ __('Create a CSV report') }}</h2><p class="mt-1 text-sm text-muted">{{ __('Analytics will generate a private download using the selected filters. The download link is available only to workspace members with current site access.') }}</p></div>
            <x-signal.ui.select-field name="days" :label="__('Report period')"><option value="7">{{ __('Last 7 days') }}</option><option value="30" selected>{{ __('Last 30 days') }}</option><option value="90">{{ __('Last 90 days') }}</option><option value="365">{{ __('Last year') }}</option></x-signal.ui.select-field>
            <x-signal.ui.input-field name="path" :label="__('Path filter')" :placeholder="__('Optional exact or partial path')" />
            <x-signal.ui.input-field name="source" :label="__('Source filter')" :placeholder="__('Optional traffic source')" />
            <x-signal.ui.input-field name="campaign" :label="__('Campaign filter')" :placeholder="__('Optional campaign')" />
            <x-signal.ui.input-field name="device" :label="__('Device filter')" :placeholder="__('Optional device category')" />
            <div class="flex items-end justify-end"><x-signal.ui.button type="submit" variant="primary">{{ __('Create report export') }}</x-signal.ui.button></div>
        </x-signal.ui.card>
    @endif

</x-signal.layouts.platform>
