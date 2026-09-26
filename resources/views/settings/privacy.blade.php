<x-signal.layouts.settings :title="__('Privacy')" :description="__('Download what we hold about you, or delete your user account.')">
    <x-signal.ui.settings-section :title="__('Download your data')" :description="__('A JSON file with your profile, accounts and roles, sign-in methods (never secrets), API token names, sign-in history and your activity. Project and service data is exported per account.')">
        <div class="p-4 sm:p-6">
            <x-signal.ui.button :href="route('settings.privacy.export')" variant="secondary">{{ __('Download my data') }}</x-signal.ui.button>
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Delete your user account')" :description="__('This can’t be undone. You are signed out everywhere and your sign-in methods, history and API tokens are erased.')">
        <div class="grid gap-5 p-4 sm:p-6">
            @if ($departure->blockedBy !== [])
                <x-signal.ui.alert tone="warning" role="status">
                    <div>
                        <p class="font-bold">{{ __('You’re the only owner of accounts other people use.') }}</p>
                        <p class="mt-1">{{ __('Make someone else an owner of these first, or remove the other members:') }}</p>
                        <ul class="mt-2 list-disc pl-5">
                            @foreach ($departure->blockedBy as $account)
                                <li>{{ $account->name }}</li>
                            @endforeach
                        </ul>
                    </div>
                </x-signal.ui.alert>
            @endif

            <dl class="grid gap-4 text-sm">
                <div>
                    <dt class="font-bold text-ink">{{ __('Deleted with you') }}</dt>
                    <dd class="mt-1 text-muted">
                        @forelse ($departure->toDelete as $account)
                            <span class="block">{{ $account->name }} — {{ __('you are its only member, so it and all its data are deleted') }}</span>
                        @empty
                            {{ __('No accounts.') }}
                        @endforelse
                    </dd>
                </div>
                <div>
                    <dt class="font-bold text-ink">{{ __('You leave') }}</dt>
                    <dd class="mt-1 text-muted">
                        @forelse ($departure->toLeave as $membership)
                            <span class="block">{{ $membership->account->name }} — {{ __('keeps working for its other members') }}</span>
                        @empty
                            {{ __('No shared accounts.') }}
                        @endforelse
                    </dd>
                </div>
            </dl>

            @if ($departure->blockedBy === [])
                <form method="POST" action="{{ route('settings.privacy.destroy') }}" class="grid gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:max-w-xl">
                    @csrf
                    @method('DELETE')
                    <x-signal.ui.input-field name="confirm_email" :label="__('Type :email to confirm', ['email' => $user->email])" autocomplete="off" required error-bag="deleteUser" :restore="false" />
                    <div><x-signal.ui.button type="submit" variant="danger">{{ __('Delete my user account') }}</x-signal.ui.button></div>
                </form>
            @endif
        </div>
    </x-signal.ui.settings-section>
</x-signal.layouts.settings>
