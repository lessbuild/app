@props(['currentWorkspace', 'workspaceOptions', 'accountUser', 'workspacePlan', 'workspaceEventCount', 'workspaceUsagePercentage', 'workspaceNavigation', 'workspaceOpenIssues', 'navigationLabel' => 'Main navigation'])

<div {{ $attributes->class(['flex min-h-full flex-col gap-7 px-4 py-5']) }}>
    <div class="flex items-center justify-between gap-2">
        <x-monitor::ui.brand class="px-2" />
        {{ $slot }}
    </div>
    <x-monitor::ui.workspace-switcher :workspace="$currentWorkspace" :workspaces="$workspaceOptions" />
    @include('monitor::layouts.navigation')
    <div class="mt-auto space-y-4 border-t border-line pt-4">
        <div class="ui-card border-primary/25 bg-primary-soft p-4 shadow-none">
            <span class="text-xs font-bold text-primary">{{ $workspacePlan['name'] }} plan</span>
            <p class="mt-2 text-[11px] leading-4 text-muted">{{ number_format($workspaceEventCount) }} metered events this month (UTC)</p>
            <div class="ui-progress mt-3" role="meter" aria-label="Monthly event allowance used" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, max(0, $workspaceUsagePercentage)) }}"><span style="width: {{ min(100, max(0, $workspaceUsagePercentage)) }}%"></span></div>
            @can('billing', $currentWorkspace)<a href="{{ route('monitor.settings.billing') }}" class="ui-link mt-3 inline-flex items-center gap-1 text-[11px]">Manage plan <x-monitor::icon name="arrow-up-right" class="h-3 w-3" /></a>@endcan
        </div>
        <div class="flex items-center gap-3 rounded-card bg-surface-muted p-3">
            <span class="ui-avatar ui-avatar-sm shrink-0">{{ mb_strtoupper(mb_substr($accountUser->name, 0, 2)) }}</span>
            <div class="min-w-0"><p class="truncate text-xs font-bold text-ink">{{ $accountUser->name }}</p><p class="truncate text-[11px] text-muted">{{ $accountUser->email }}</p></div>
        </div>
        <form method="POST" action="{{ route('monitor.logout') }}">@csrf<x-monitor::ui.button variant="quiet" size="sm" class="w-full justify-start">Sign out</x-monitor::ui.button></form>
    </div>
</div>
