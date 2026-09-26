@php
    $statusTone = match ($report->status) {
        'ready' => 'success',
        'review' => 'warning',
        default => 'danger',
    };
@endphp

<x-signal.layouts.platform
    :title="__('Handover validation report')"
    :description="__('Read-only destination validation report for a project handover manifest.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name.' · '.__('Projects')"
        :title="__('Handover validation report')"
        :description="__('This report reflects current destination access and provider availability. It does not provision or transfer data.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.projects.handover.form', $workspace)">{{ __('Validate another manifest') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.projects.index', $workspace)">{{ __('Back to projects') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.alert :tone="$statusTone" class="mb-6" role="status">
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-extrabold">{{ str($report->status)->headline() }}</span>
            <span>{{ match ($report->status) {
                'ready' => __('No current blockers were found. Review the source identity and product setup before any future import.'),
                'review' => __('Review the findings below before proceeding with any future import.'),
                default => __('Resolve the blockers below before this manifest can be considered for a future import.'),
            } }}</span>
        </div>
    </x-signal.ui.alert>

    <section aria-label="{{ __('Manifest counts') }}" class="mb-6 grid gap-3 sm:grid-cols-3">
        <x-signal.ui.stat :label="__('Environments')" :value="$report->counts['environments']" />
        <x-signal.ui.stat :label="__('Resource mappings')" :value="$report->counts['resources']" />
        <x-signal.ui.stat :label="__('Connections')" :value="$report->counts['connections']" />
    </section>

    @if ($report->products !== [])
        <section aria-labelledby="handover-products-heading" class="mb-6">
            <h2 id="handover-products-heading" class="mb-3 text-lg font-extrabold text-ink">{{ __('Destination product access and plans') }}</h2>
            <div class="grid gap-3 lg:grid-cols-3">
                @foreach ($report->products as $product => $details)
                    <x-signal.ui.card class="p-5">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-extrabold text-ink">{{ str($product)->headline() }}</h3>
                            <x-signal.ui.badge :tone="$details['access'] ? 'success' : 'danger'">{{ $details['access'] ? __('Access available') : __('Access required') }}</x-signal.ui.badge>
                        </div>
                        @if ($details['plan_available'] !== null)
                            <p class="mt-3 text-sm text-muted">{{ __('Plan') }}: {{ $details['plan_available'] ? __('Available') : __('Unavailable') }}</p>
                        @elseif (! $details['access'])
                            <p class="mt-3 text-sm text-muted">{{ __('Plan status is hidden until this workspace has product access.') }}</p>
                        @else
                            <p class="mt-3 text-sm text-muted">{{ __('Detailed plan status is limited to billing managers.') }}</p>
                        @endif
                        @if ($details['limits_visible'] && $details['limits'] !== [])
                            <dl class="mt-4 grid gap-2 border-t border-line pt-3 text-sm">
                                @foreach ($details['limits'] as $name => $limit)
                                    <div class="flex items-center justify-between gap-4">
                                        <dt class="text-muted">{{ str($name)->replace('_', ' ')->headline() }}</dt>
                                        <dd class="font-bold text-ink">{{ $limit ?? __('Unlimited') }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @elseif (! $details['limits_visible'])
                            <p class="mt-4 border-t border-line pt-3 text-xs leading-5 text-muted">{{ __('Plan limits are visible to workspace owners and billing managers.') }}</p>
                        @endif
                    </x-signal.ui.card>
                @endforeach
            </div>
        </section>
    @endif

    <section aria-labelledby="handover-findings-heading" class="mb-6">
        <h2 id="handover-findings-heading" class="mb-3 text-lg font-extrabold text-ink">{{ __('Validation findings') }}</h2>
        @if ($report->findings === [])
            <x-signal.ui.empty-state :title="__('No issues found')" :description="__('The destination checks passed for the references in this manifest. This result is not an import approval or transfer.')" icon="check-circle" />
        @else
            <div class="grid gap-3">
                @foreach ($report->findings as $finding)
                    <x-signal.ui.panel class="flex flex-wrap items-start gap-3 p-4">
                        <x-signal.ui.badge :tone="match ($finding->severity) { 'blocker' => 'danger', 'review' => 'warning', default => 'neutral' }">{{ str($finding->severity)->headline() }}</x-signal.ui.badge>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-ink">{{ $finding->message }}</p>
                            @if ($finding->reference)
                                <p class="mt-1 font-mono text-xs text-muted">{{ $finding->reference }}</p>
                            @endif
                        </div>
                    </x-signal.ui.panel>
                @endforeach
            </div>
        @endif
    </section>

    @if ($report->resources !== [])
        <section aria-labelledby="handover-resources-heading" class="mb-6">
            <h2 id="handover-resources-heading" class="mb-3 text-lg font-extrabold text-ink">{{ __('Resource mapping checks') }}</h2>
            <div class="grid gap-2">
                @foreach ($report->resources as $resource)
                    <x-signal.ui.panel class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <span class="font-mono text-xs text-muted">{{ $resource['reference'] }}</span>
                        <x-signal.ui.badge tone="neutral">{{ str($resource['state'])->replace('_', ' ')->headline() }}</x-signal.ui.badge>
                    </x-signal.ui.panel>
                @endforeach
            </div>
        </section>
    @endif

    <x-signal.ui.alert tone="info" role="note">
        {{ __('Subscriptions remain workspace-owned. A handover manifest is not a backup and does not move billing, change ownership, create resources, or modify either workspace.') }}
    </x-signal.ui.alert>
</x-signal.layouts.platform>
