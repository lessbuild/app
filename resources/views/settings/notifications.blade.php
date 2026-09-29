<x-signal.layouts.settings :title="__('Notifications')" :description="$account ? __('Emails you get from :account. Switch accounts to change another’s.', ['account' => $account->name]) : __('Emails you get from your accounts.')">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    <x-signal.ui.settings-section :title="__('Daily issue digest')" :description="__('Each morning at 08:00 UTC: new and resolved issues across the account’s projects, and how many are still open. Owners get it unless they turn it off.')">
        @if ($digestAvailable)
            <form method="POST" action="{{ route('settings.notifications.update') }}" class="grid gap-4 p-4 sm:p-6">
                @csrf
                @method('PUT')
                <x-signal.ui.checkbox name="issue_digest" value="1" unchecked-value="0" :checked="$digestEnabled">{{ __('Email me the daily issue digest') }}</x-signal.ui.checkbox>
                <div><x-signal.ui.button type="submit" variant="secondary">{{ __('Save') }}</x-signal.ui.button></div>
            </form>
        @else
            <p class="p-4 text-sm text-muted sm:p-6">{{ __('The digest is for members who can use Monitoring in this account.') }}</p>
        @endif
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Getting started')" :description="__('A welcome when you join, and one reminder a few days later if a project’s setup has stalled.')">
        <form method="POST" action="{{ route('settings.getting-started-emails.update') }}" class="grid gap-4 p-4 sm:p-6">
            @csrf
            @method('PUT')
            <x-signal.ui.checkbox name="getting_started_emails" value="1" unchecked-value="0" :checked="auth()->user()?->getting_started_emails ?? true">{{ __('Email me getting-started tips') }}</x-signal.ui.checkbox>
            <div><x-signal.ui.button type="submit" variant="secondary">{{ __('Save') }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Always sent')" :description="__('Security emails, invitations and, for owners, Monitoring usage alerts at 80% and 100% of the monthly allowance.')">
        <p class="p-4 text-sm text-muted sm:p-6">{{ __('These can’t be turned off.') }}</p>
    </x-signal.ui.settings-section>
</x-signal.layouts.settings>
