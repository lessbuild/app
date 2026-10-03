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

    <x-signal.ui.settings-section :title="__('Weekly report')" :description="__('Each Monday at 08:00 UTC: last week’s deploys, incidents, uptime and visits for each project in your accounts, compared with the week before. Nothing is sent for a quiet week.')">
        <form method="POST" action="{{ route('settings.weekly-report-emails.update') }}" class="grid gap-4 p-4 sm:p-6">
            @csrf
            @method('PUT')
            <x-signal.ui.checkbox name="weekly_report_emails" value="1" unchecked-value="0" :checked="auth()->user()?->weekly_report_emails ?? true">{{ __('Email me the weekly report') }}</x-signal.ui.checkbox>
            <div><x-signal.ui.button type="submit" variant="secondary">{{ __('Save') }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section id="push" :title="__('Push notifications')" :description="__('Get alerts and incidents on your phone or computer, even when BuildPusher isn’t open. On iPhone and iPad, first add BuildPusher to your Home Screen (Share → Add to Home Screen) and turn this on from there. Alert destinations of the Push type then reach these devices.')">
        <div class="grid gap-4 p-4 sm:p-6" data-push-devices data-push-key="{{ $pushKey }}" data-push-url="{{ route('settings.push-devices.store') }}" data-push-worker="{{ asset('sw.js') }}">
            @forelse ($pushDevices as $device)
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span><span class="font-bold text-ink">{{ $device->device ?? __('A device') }}</span> <span class="text-muted">· {{ __('added :time', ['time' => $device->created_at?->diffForHumans()]) }}@if ($device->last_used_at) · {{ __('last notified :time', ['time' => $device->last_used_at->diffForHumans()]) }}@endif</span></span>
                    <form method="POST" action="{{ route('settings.push-devices.destroy', $device->id) }}">
                        @csrf @method('DELETE')
                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('No devices yet.') }}</p>
            @endforelse
            <p class="text-sm text-danger" data-push-message hidden></p>
            <div class="flex flex-wrap gap-2">
                <x-signal.ui.button type="button" variant="secondary" data-push-enable>{{ __('Turn on for this device') }}</x-signal.ui.button>
                @if ($pushDevices->isNotEmpty())
                    <form method="POST" action="{{ route('settings.push-devices.test') }}">@csrf<x-signal.ui.button type="submit" variant="quiet">{{ __('Send a test') }}</x-signal.ui.button></form>
                @endif
            </div>
        </div>
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
