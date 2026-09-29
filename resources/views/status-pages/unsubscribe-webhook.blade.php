<x-signal.layouts.auth :title="__('Unsubscribe')" :heading="__('Stop :page updates?', ['page' => $subscription->statusPage->name])" :description="$subscription->type === 'slack' ? __('Updates from this status page won’t be posted to that Slack channel any more.') : __('Updates from this status page won’t be sent to that webhook any more.')">
    <form method="POST" action="{{ route('status.webhooks.unsubscribe.store', [$subscription->id, $token]) }}" class="flex flex-wrap gap-3">
        @csrf
        <x-signal.ui.button type="submit" variant="primary">{{ __('Unsubscribe') }}</x-signal.ui.button>
        <x-signal.ui.button :href="route('status.show', $subscription->statusPage->slug)" variant="quiet">{{ __('Keep it subscribed') }}</x-signal.ui.button>
    </form>
</x-signal.layouts.auth>
