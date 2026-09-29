<x-signal.layouts.auth :title="__('Getting-started emails')" :heading="$action ? __('Stop getting-started emails?') : __('Done')" :description="$action ? __('No more welcome or setup-reminder emails to :email. Security emails and alerts you’ve set up still arrive.', ['email' => $person->email]) : __('You won’t get getting-started emails again. You can turn them back on in your notification settings.')">
    @if ($action)
        <form method="POST" action="{{ $action }}">
            @csrf
            <x-signal.ui.button type="submit" variant="primary" class="w-full justify-center">{{ __('Stop these emails') }}</x-signal.ui.button>
        </form>
    @endif
</x-signal.layouts.auth>
