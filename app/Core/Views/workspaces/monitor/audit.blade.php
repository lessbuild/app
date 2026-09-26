<x-signal.layouts.platform :title="__('Monitor audit log')" :description="__('Review recent Monitor workspace administration events.')" :navigation="[]" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces" :context-projects="$contextProjects">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Monitor audit log')" :description="__('Recent administrative actions from Monitor, filtered by your current source access.')">
        <x-slot:actions><x-signal.ui.button :href="route('core.workspace.monitor.settings', $workspace)" variant="secondary">{{ __('Monitor settings') }}</x-signal.ui.button><x-signal.ui.button :href="route('core.workspace.monitor.export', $workspace)" variant="primary">{{ __('Export data') }}</x-signal.ui.button></x-slot:actions>
    </x-signal.ui.page-header>
    @if (! ($settings['audit_available'] ?? false))
        <x-signal.ui.card class="mt-6 p-4"><h2 class="font-bold text-ink">{{ __('Audit history unavailable') }}</h2><p class="mt-1 text-sm text-muted">{{ __('The current Monitor plan does not include workspace audit history.') }}</p></x-signal.ui.card>
    @else
    <x-signal.ui.card class="mt-6 p-4">
        <form method="GET" action="{{ route('core.workspace.monitor.audit', $workspace) }}" class="flex flex-wrap items-end gap-3">
            <x-signal.ui.input-field id="audit-search" name="audit_search" :label="__('Find audit events')" :value="request('audit_search')" maxlength="100" />
            <x-signal.ui.button type="submit" variant="secondary">{{ __('Search audit history') }}</x-signal.ui.button>
        </form>
    </x-signal.ui.card>
    <div class="mt-6 grid gap-3">
        @forelse ($items as $item)
            <x-signal.ui.card class="p-4"><div class="flex flex-wrap justify-between gap-2"><h2 class="font-bold text-ink">{{ $item['label'] }} · {{ $item['subject'] }}</h2><time class="text-xs text-muted">{{ $item['occurred_at']?->toDayDateTimeString() }}</time></div><p class="mt-1 text-sm text-muted">{{ __('By :actor', ['actor' => $item['actor']]) }}</p>@if(count($item['metadata']) > 1)<pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs text-subtle">{{ json_encode(collect($item['metadata'])->except('label')->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>@endif</x-signal.ui.card>
        @empty
            <x-signal.ui.empty-state :title="__('No audit events')" :description="__('There are no visible Monitor administration events for this workspace.')" icon="clock" />
        @endforelse
    </div>
    @if ($items->hasPages())<nav class="mt-5" aria-label="{{ __('Audit history pages') }}">{{ $items->links() }}</nav>@endif
    @endif
</x-signal.layouts.platform>
