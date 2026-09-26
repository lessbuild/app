<x-signal.layouts.platform :title="__($feature['label'])" :account-user="$user" :current-workspace="$workspace" :workspaces="collect([$workspace])">
    <x-signal.ui.empty-state :title="__('This shared view is paused')" :description="__('The rollout setting for this workspace currently pauses this view. Existing app tools remain available through workspace management. Your data, permissions, subscriptions, and background work are unchanged.')">
        <x-slot:action>
            <x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="primary">{{ __('Open app tools') }}</x-signal.ui.button>
            @if ($canManage)
                <x-signal.ui.button :href="route('core.workspace.feature-rollouts.index', $workspace)" variant="secondary">{{ __('Review rollout settings') }}</x-signal.ui.button>
            @endif
        </x-slot:action>
    </x-signal.ui.empty-state>
</x-signal.layouts.platform>
