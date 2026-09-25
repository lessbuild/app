<x-signal.layouts.platform
    :title="__('Resource restoration')"
    :description="__('Track your Monitor restoration and retry interrupted work.')"
    :navigation="[]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Resource restoration')" :description="__('Restoration reconnects this resource to its shared project. Collection settings and credentials remain under your control.')">
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.projects.index', ['workspace' => $workspace, 'status' => 'archived'])" variant="secondary">{{ __('Archived projects') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('platform.resource-restorations.show', $restoration)" variant="secondary">{{ __('Refresh progress') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.card class="mt-6 p-6" aria-live="polite">
        @php($statusLabel = match ($restoration->status) {
            'pending' => __('Queued'), 'processing' => __('Restoring'), 'completed' => __('Restored'),
            'blocked' => __('Needs attention'), 'superseded' => __('Resource changed'), default => __('Retry needed'),
        })
        <p class="ui-eyebrow">{{ __('Monitor :type', ['type' => $restoration->resource_type]) }}</p>
        <h2 class="mt-2 text-lg font-extrabold text-ink">{{ $statusLabel }}</h2>
        <p class="mt-3 text-sm text-muted">{{ __('Requested :date', ['date' => $restoration->created_at->format('M j, Y H:i T')]) }}</p>
        @if ($restoration->status === 'completed')
            <p class="mt-3 text-sm text-muted">{{ __('The resource is restored. Independently archived children stay archived, paused collection stays paused, and revoked credentials stay revoked. Open Monitor to review collector settings.') }}</p>
        @elseif (in_array($restoration->status, ['pending', 'processing'], true))
            <p class="mt-3 text-sm text-muted">{{ __('Restoration continues in the background. You can leave this page and return to check progress.') }}</p>
        @endif
        @if ($restoration->available_at && $restoration->status === 'pending')
            <p class="mt-3 text-sm text-muted">{{ __('Next attempt: :date', ['date' => $restoration->available_at->format('M j, Y H:i T')]) }}</p>
        @endif
        @if ($errorMessage)
            <x-signal.ui.alert tone="warning" class="mt-4">{{ $errorMessage }}</x-signal.ui.alert>
        @endif
        @if ($errors->has('restoration'))
            <x-signal.ui.alert tone="danger" class="mt-4">{{ $errors->first('restoration') }}</x-signal.ui.alert>
        @endif
        @if ($canRetry)
            <form method="POST" action="{{ route('platform.resource-restorations.retry', $restoration) }}" class="mt-5">
                @csrf
                <x-signal.ui.button type="submit" variant="primary">{{ $restoration->status === 'superseded' ? __('Start a new restoration') : ($restoration->actor_id === (string) $user->getKey() ? __('Retry restoration') : __('Continue restoration with my access')) }}</x-signal.ui.button>
            </form>
        @endif
    </x-signal.ui.card>
</x-signal.layouts.platform>
