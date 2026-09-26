<x-signal.layouts.account :title="__('Open deletion receipt')" :description="__('Enter the recovery key you saved when confirming deletion.')">
    <x-signal.ui.panel as="section" class="p-6">
        <form method="POST" action="{{ route('platform.deletions.recover', $receiptKey) }}" class="max-w-xl space-y-4">
            @csrf
            <x-signal.ui.input-field name="token" :label="__('Recovery key')" type="password" :restore="false" required autocomplete="off" />
            <x-signal.ui.button type="submit" variant="primary">{{ __('Open receipt') }}</x-signal.ui.button>
        </form>
    </x-signal.ui.panel>
</x-signal.layouts.account>
