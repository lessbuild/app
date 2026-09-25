<x-signal.layouts.platform :title="__('Save this signing key')" :description="__('A newly issued Monitor signing key is displayed once.')" :navigation="[]" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces" :context-projects="$contextProjects">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Save this signing key now')" :description="__('Monitor will not show this value again. Store it in your secret manager and update your receiver.')">
        <x-slot:actions><x-signal.ui.button :href="route('core.workspace.monitor.destinations', $workspace)" variant="primary">{{ __('Return to destinations') }}</x-signal.ui.button></x-slot:actions>
    </x-signal.ui.page-header>
    <x-signal.ui.alert tone="warning" class="mt-5">{{ $status }}</x-signal.ui.alert>
    <x-signal.ui.card class="mt-5 p-5"><p class="text-sm font-semibold">{{ __('One-time signing key') }}</p><pre class="mt-2 overflow-x-auto rounded-panel border border-line bg-subtle p-3 font-mono text-sm text-ink">{{ $secret }}</pre><p class="mt-2 text-xs text-muted">{{ __('This private response is not cached or stored in session flash data.') }}</p></x-signal.ui.card>
</x-signal.layouts.platform>
