<x-signal.layouts.platform :title="__('Monitor integration guide')" :description="__('Connect services to Monitor with scoped telemetry credentials.')" :navigation="[]" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces" :context-projects="$contextProjects">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Monitor integrations guide')" :description="__('Connect an application and environment, then verify its telemetry flow.')">
        <x-slot:actions><x-signal.ui.button :href="route('core.workspace.credentials', $workspace)" variant="secondary">{{ __('Credential inventory') }}</x-signal.ui.button><x-signal.ui.button :href="route('core.workspace.monitor.settings', $workspace)" variant="secondary">{{ __('Monitor settings') }}</x-signal.ui.button><x-signal.ui.button :href="route('core.help.monitor.api')" variant="primary">{{ __('API reference') }}</x-signal.ui.button></x-slot:actions>
    </x-signal.ui.page-header>
    <x-signal.ui.alert tone="info" class="mt-5">{{ __('Integration credentials are created and rotated in Monitor. Core never displays token values.') }}</x-signal.ui.alert>
    <ol class="mt-6 grid gap-4">
        @foreach ($items as $item)
            <li><x-signal.ui.card class="p-5"><h2 class="text-lg font-extrabold text-ink">{{ $item['title'] }}</h2><p class="mt-2 break-words text-sm leading-6 text-muted">{{ $item['detail'] }}</p></x-signal.ui.card></li>
        @endforeach
    </ol>
</x-signal.layouts.platform>
