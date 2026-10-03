<x-signal.layouts.auth :title="__('Unsubscribe')" :heading="__('Stop :page emails?', ['page' => $subscription->statusPage->name])" :description="__('You won’t get incident or maintenance updates from this status page any more.')">
    <form method="POST" action="{{ route('status.subscriptions.unsubscribe.store', [$subscription->id, $token]) }}" class="flex flex-wrap gap-3">
        @csrf
        <x-signal.ui.button type="submit" variant="primary">{{ __('Unsubscribe') }}</x-signal.ui.button>
        <x-signal.ui.button :href="route('status.show', $subscription->statusPage->slug)" variant="quiet">{{ __('Keep me subscribed') }}</x-signal.ui.button>
    </form>
</x-signal.layouts.auth>
