<x-signal.layouts.platform :title="__('Monitor settings and privacy')" :description="__('Manage Monitor notification preferences, audit history, and workspace export.')" :navigation="[]" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces" :context-projects="$contextProjects">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Monitor settings and privacy')" :description="__('Manage your Monitor issue digest and export workspace data.')">
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.monitor.alerts', $workspace)" variant="secondary">{{ __('Alert rules') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.monitor.destinations', $workspace)" variant="secondary">{{ __('Destinations') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.workspace.monitor.audit', $workspace)" variant="secondary">{{ __('Audit log') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @if (session('status')) <x-signal.ui.alert tone="success" class="mt-5">{{ session('status') }}</x-signal.ui.alert> @endif
    @if ($errors->any()) <x-signal.ui.alert tone="danger" class="mt-5">{{ $errors->first() }}</x-signal.ui.alert> @endif

    <div class="mt-6 grid gap-5 lg:grid-cols-2">
        <x-signal.ui.card class="p-5">
            <h2 class="text-lg font-extrabold text-ink">{{ __('Issue digest') }}</h2>
            @if ($settings['digest_available'])
                <form method="POST" action="{{ route('core.workspace.monitor.settings.notifications', $workspace) }}" class="mt-4 grid gap-3">
                    @csrf @method('PUT')
                    <x-signal.ui.checkbox name="digest_enabled" :checked="$settings['digest_enabled']" :restore="false" unchecked-value="0">{{ __('Send me a daily issue digest for scopes I can access.') }}</x-signal.ui.checkbox>
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Save preference') }}</x-signal.ui.button>
                </form>
            @else
                <p class="mt-3 text-sm leading-6 text-muted">{{ __('Issue digests are not available on the current plan.') }}</p>
            @endif
            <p class="mt-3 text-xs text-muted">{{ __('This preference applies to your Monitor account in this workspace. Digest delivery remains managed by Monitor.') }}</p>
        </x-signal.ui.card>
        <x-signal.ui.card class="p-5">
            <h2 class="text-lg font-extrabold text-ink">{{ __('Data and privacy') }}</h2>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('Download a private NDJSON export of workspace data allowed by your current Monitor role and project mappings.') }}</p>
            <div class="mt-4 flex flex-wrap gap-2"><x-signal.ui.button :href="route('core.workspace.monitor.export', $workspace)" variant="primary">{{ __('Export Monitor data') }}</x-signal.ui.button><x-signal.ui.button :href="route('core.workspace.monitor.integrations', $workspace)" variant="secondary">{{ __('Integrations guide') }}</x-signal.ui.button></div>
        </x-signal.ui.card>
    </div>

    <x-signal.ui.card class="mt-6 p-5">
        <h2 class="text-lg font-extrabold text-ink">{{ __('Recent digest deliveries') }}</h2>
        <div class="mt-3 divide-y divide-line">
            @forelse ($items as $item)
                <div class="grid gap-1 py-3 sm:grid-cols-4"><p class="font-semibold">{{ ucfirst($item['status']) }}</p><p class="text-sm text-muted">{{ __('Period ending :date', ['date' => $item['period_end']?->toDayDateTimeString()]) }}</p><p class="text-sm text-muted">{{ $item['sent_at']?->diffForHumans() ?: ($item['last_error_code'] ?? __('Not sent')) }}</p><p class="text-sm text-muted">@if($item['summary_available']){{ __(':new new · :resolved resolved · :open open · :critical critical', ['new' => $item['new_count'], 'resolved' => $item['resolved_count'], 'open' => $item['open_count'], 'critical' => $item['critical_open_count']]) }}@else{{ __('Summary unavailable for your current access.') }}@endif</p></div>
            @empty
                <p class="py-4 text-sm text-muted">{{ __('No digest delivery history is available.') }}</p>
            @endforelse
        </div>
    </x-signal.ui.card>
</x-signal.layouts.platform>
