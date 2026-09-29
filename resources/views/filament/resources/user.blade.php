{{-- One person in the admin panel: how they sign in, their accounts and latest sign-ins. Opening it is recorded. --}}
<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __(':name · joined :date', ['name' => $user->name, 'date' => $user->created_at?->toFormattedDateString()]) }}</p>

    <div class="grid gap-6 sm:grid-cols-3">
        @foreach ([[__('Email'), $user->email_verified_at ? __('Verified') : __('Not verified')], [__('Authenticator app'), $user->two_factor_confirmed_at ? __('On') : __('Off')], [__('Passkeys and providers'), __(':passkeys passkeys, :providers providers', ['passkeys' => $passkeys, 'providers' => $user->socialIdentities->count()])]] as [$label, $value])
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-lg font-semibold">{{ $value }}</p>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section :heading="__('Accounts')">
        <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
            @forelse ($user->memberships as $membership)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <x-filament::link :href="\App\Filament\Resources\Accounts\AccountResource::getUrl('view', ['record' => $membership->account_id])">{{ $membership->account->name }}</x-filament::link>
                    <span class="text-gray-500 dark:text-gray-400">{{ $membership->role->label() }}</span>
                </li>
            @empty
                <li class="py-2 text-gray-500 dark:text-gray-400">{{ __('No accounts.') }}</li>
            @endforelse
        </ul>
    </x-filament::section>

    <x-filament::section :heading="__('Latest sign-ins')">
        <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
            @forelse ($signIns as $event)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>{{ $event->created_at->toDayDateTimeString() }} · {{ $event->method?->label() ?? '—' }}@if ($event->two_factor) · 2FA @endif</span>
                    <span class="flex items-center gap-2">
                        <x-filament::badge :color="$event->succeeded ? 'success' : 'danger'">{{ $event->succeeded ? __('Signed in') : __('Failed') }}</x-filament::badge>
                        <span class="text-gray-500 dark:text-gray-400">{{ $event->ip_address ?? '—' }}</span>
                    </span>
                </li>
            @empty
                <li class="py-2 text-gray-500 dark:text-gray-400">{{ __('No sign-ins recorded.') }}</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
