<x-signal.layouts.admin :title="$user->email" :description="__(':name · joined :date', ['name' => $user->name, 'date' => $user->created_at?->toFormattedDateString()])">
    <div class="grid gap-4 sm:grid-cols-3">
        <x-signal.ui.stat :label="__('Email')" :value="$user->email_verified_at ? __('Verified') : __('Not verified')" />
        <x-signal.ui.stat :label="__('Authenticator app')" :value="$user->two_factor_confirmed_at ? __('On') : __('Off')" />
        <x-signal.ui.stat :label="__('Passkeys and providers')" :value="__(':passkeys passkeys, :providers providers', ['passkeys' => $passkeys, 'providers' => $user->socialIdentities->count()])" />
    </div>

    <x-signal.ui.table :caption="__('Accounts')">
        <x-slot:head><tr><th scope="col">{{ __('Account') }}</th><th scope="col">{{ __('Role') }}</th></tr></x-slot:head>
        @forelse ($user->memberships as $membership)
            <tr><td><a href="{{ route('admin.customers.accounts', $membership->account_id) }}" class="text-primary hover:underline">{{ $membership->account->name }}</a></td><td>{{ $membership->role->label() }}</td></tr>
        @empty
            <tr><td colspan="2" class="text-muted">{{ __('No accounts.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>

    <x-signal.ui.table :caption="__('Latest sign-ins')">
        <x-slot:head><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('Result') }}</th><th scope="col">{{ __('How') }}</th><th scope="col">{{ __('From') }}</th></tr></x-slot:head>
        @forelse ($signIns as $event)
            <tr>
                <td>{{ $event->created_at->toDayDateTimeString() }}</td>
                <td><x-signal.ui.badge :tone="$event->succeeded ? 'success' : 'danger'">{{ $event->succeeded ? __('Signed in') : __('Failed') }}</x-signal.ui.badge></td>
                <td>{{ $event->method?->label() ?? '—' }}@if ($event->two_factor) · 2FA @endif</td>
                <td class="text-muted">{{ $event->ip_address ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-muted">{{ __('No sign-ins recorded.') }}</td></tr>
        @endforelse
    </x-signal.ui.table>
</x-signal.layouts.admin>
