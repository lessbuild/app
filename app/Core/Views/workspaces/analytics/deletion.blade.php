<x-signal.layouts.platform
    :title="__('Analytics site deletion')"
    :description="__('View the cleanup status for an Analytics site.')"
    :navigation="$navigation"
    :account-user="$accountUser"
    :current-workspace="$currentWorkspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Analytics site deletion')" :description="__('Collection is fenced while Analytics removes site data and private export files.')">
        <x-slot:actions><x-signal.ui.button :href="route('core.workspace.analytics.sites.index', $workspace)" variant="secondary">{{ __('All sites') }}</x-signal.ui.button></x-slot:actions>
    </x-signal.ui.page-header>
    <x-signal.ui.card class="mt-6 p-5">
        @if ($outcome->completed())
            <x-signal.ui.alert tone="success" role="status">{{ __('The Analytics site has been deleted.') }}</x-signal.ui.alert>
        @elseif ($outcome->status === 'blocked')
            <x-signal.ui.alert tone="danger" role="status">{{ __('Deletion needs operator attention before cleanup can finish.') }}</x-signal.ui.alert>
        @else
            <x-signal.ui.alert tone="warning" role="status">{{ __('The site is fenced. Cleanup is waiting and remains retryable.') }}</x-signal.ui.alert>
            <form class="mt-5 flex justify-end" method="POST" action="{{ route('core.workspace.analytics.sites.deletion-retry', [$workspace, $outcome->requestId]) }}">
                @csrf
                <x-signal.ui.button type="submit" variant="primary">{{ __('Retry cleanup') }}</x-signal.ui.button>
            </form>
        @endif
        <p class="mt-4 text-xs text-muted">{{ __('Request reference: :id', ['id' => $outcome->requestId]) }}</p>
    </x-signal.ui.card>
</x-signal.layouts.platform>
