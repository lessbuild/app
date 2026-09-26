@use('App\Domain\Identity\Enums\TwoFactorState')
@php($status = session('status'))
@php($statusMessages = [
    'password-updated' => __('Password saved. Other browsers that were remembered have been signed out.'),
    'two-factor-authentication-confirmed' => __('Two-factor authentication is on.'),
    'two-factor-authentication-disabled' => __('Two-factor authentication is off.'),
    'recovery-codes-generated' => __('New recovery codes were created. The old ones no longer work.'),
    'passkey-deleted' => __('Passkey removed.'),
    'social-connected' => __('Account connected. You can now sign in with it.'),
    'social-already-connected' => __('That account was already connected.'),
    'social-disconnected' => __('Account disconnected.'),
    'social-not-connected' => __('That account wasn’t connected.'),
])

<x-signal.layouts.settings :title="__('Security')" :description="__('How you sign in to :app.', ['app' => config('app.name')])">
    @if (is_string($status) && isset($statusMessages[$status]))
        <x-signal.ui.alert tone="success" role="status">{{ $statusMessages[$status] }}</x-signal.ui.alert>
    @endif

    @if ($security->recoveryCodes !== [])
        <x-signal.ui.panel as="section" class="space-y-4 border-warning p-6" aria-labelledby="recovery-codes-heading">
            <div>
                <p class="ui-eyebrow">{{ __('Save these now') }}</p>
                <h2 id="recovery-codes-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Recovery codes') }}</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('Each code signs you in once if you lose your authenticator. Store them somewhere private; they are not shown again.') }}</p>
            </div>
            <ul class="grid gap-2 font-mono text-sm sm:grid-cols-2" aria-label="{{ __('One-time recovery codes') }}">
                @foreach ($security->recoveryCodes as $code)
                    <li class="rounded-control border border-line bg-surface-muted px-3 py-2 text-ink">{{ $code }}</li>
                @endforeach
            </ul>
        </x-signal.ui.panel>
    @endif

    <x-signal.ui.settings-section :title="$security->hasPassword ? __('Password') : __('Set a password')" :description="__('Saving a new password signs out other browsers that were remembered.')">
        <form method="POST" action="{{ route('user-password.update') }}" class="grid gap-5 p-4 sm:p-6">
            @csrf
            @method('PUT')
            @if ($security->hasPassword)
                <x-signal.ui.input-field name="current_password" :label="__('Current password')" type="password" autocomplete="current-password" required error-bag="updatePassword" />
            @endif
            <x-signal.ui.input-field name="password" :label="__('New password')" type="password" autocomplete="new-password" required error-bag="updatePassword" />
            <x-signal.ui.input-field name="password_confirmation" :label="__('Confirm new password')" type="password" autocomplete="new-password" required error-bag="updatePassword" />
            <div><x-signal.ui.button type="submit" variant="primary">{{ $security->hasPassword ? __('Update password') : __('Set password') }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Two-factor authentication')" :description="__('Ask for a code from an authenticator app whenever you sign in with a password.')">
        <div class="grid gap-5 p-4 sm:p-6">
            <div>
                <x-signal.ui.badge :tone="$security->twoFactor === TwoFactorState::On ? 'success' : 'neutral'">
                    {{ match ($security->twoFactor) { TwoFactorState::On => __('On'), TwoFactorState::Pending => __('Finish setup'), TwoFactorState::Off => __('Off') } }}
                </x-signal.ui.badge>
            </div>

            @if ($security->twoFactor === TwoFactorState::On)
                <div class="flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
                        @csrf
                        <x-signal.ui.button type="submit" variant="secondary">{{ __('Create new recovery codes') }}</x-signal.ui.button>
                    </form>
                    <form method="POST" action="{{ route('two-factor.disable') }}">
                        @csrf
                        @method('DELETE')
                        <x-signal.ui.button type="submit" variant="danger">{{ __('Turn off two-factor authentication') }}</x-signal.ui.button>
                    </form>
                </div>
            @elseif ($security->twoFactor === TwoFactorState::Pending)
                <p class="text-sm leading-6 text-muted">{{ __('Scan this code with your authenticator app, or enter the setup key by hand, then type the code it shows.') }}</p>
                <div class="w-fit rounded-control border border-line bg-white p-3" data-two-factor-qr-code>{!! $security->pendingQrCodeSvg !!}</div>
                <x-signal.ui.input-field name="setup_key" :label="__('Setup key')" :value="$security->pendingSecret" readonly :restore="false" :error-key="false" class="font-mono" />
                <form method="POST" action="{{ route('two-factor.confirm') }}" class="grid gap-4 sm:max-w-sm">
                    @csrf
                    <x-signal.ui.input-field name="code" :label="__('Code from your app')" inputmode="numeric" autocomplete="one-time-code" required error-bag="confirmTwoFactorAuthentication" :restore="false" />
                    <div><x-signal.ui.button type="submit" variant="primary">{{ __('Confirm and turn on') }}</x-signal.ui.button></div>
                </form>
                <form method="POST" action="{{ route('two-factor.disable') }}">
                    @csrf
                    @method('DELETE')
                    <x-signal.ui.button type="submit" variant="quiet">{{ __('Cancel setup') }}</x-signal.ui.button>
                </form>
            @else
                <form method="POST" action="{{ route('two-factor.enable') }}">
                    @csrf
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Set up an authenticator app') }}</x-signal.ui.button>
                </form>
            @endif
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Passkeys')" :description="__('Sign in with your device screen lock, a biometric or a security key instead of a password.')">
        <div class="grid gap-5 p-4 sm:p-6" data-passkey-surface>
            @if ($security->passkeys === [])
                <p class="text-sm text-muted">{{ __('No passkeys yet.') }}</p>
            @else
                <ul class="grid gap-3" aria-label="{{ __('Your passkeys') }}">
                    @foreach ($security->passkeys as $passkey)
                        <li class="flex flex-wrap items-center justify-between gap-4 rounded-panel border border-line p-4">
                            <div class="min-w-0">
                                <p class="break-words text-sm font-bold text-ink">{{ $passkey->name }}</p>
                                <p class="mt-1 text-xs leading-5 text-muted">
                                    {{ $passkey->authenticator ?? __('Unknown authenticator') }}
                                    · {{ __('Added :time', ['time' => $passkey->createdAt?->diffForHumans() ?? __('recently')]) }}
                                    · {{ __('Last used :time', ['time' => $passkey->lastUsedAt?->diffForHumans() ?? __('never')]) }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('passkey.destroy', $passkey->id) }}">
                                @csrf
                                @method('DELETE')
                                <x-signal.ui.button type="submit" variant="quiet" :aria-label="__('Remove passkey :name', ['name' => $passkey->name])">{{ __('Remove') }}</x-signal.ui.button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form
                method="POST"
                action="{{ route('passkey.store') }}"
                data-passkey-registration
                data-options-url="{{ route('passkey.registration-options') }}"
                data-working-message="{{ __('Follow your device prompts to add a passkey…') }}"
                data-failed-message="{{ __('The passkey could not be added. Please try again.') }}"
                data-unsupported-message="{{ __('This browser does not support passkeys.') }}"
                class="grid gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:max-w-xl"
            >
                @csrf
                <x-signal.ui.input-field name="name" :label="__('Passkey name')" :description="__('A name you will recognise, such as “Work laptop”.')" required maxlength="255" autocomplete="off" />
                <div><x-signal.ui.button type="submit" variant="primary">{{ __('Add a passkey') }}</x-signal.ui.button></div>
            </form>
            <p data-passkey-status role="status" aria-live="polite" class="min-h-5 text-sm text-muted"></p>
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Connected accounts')" :description="__('Sign in with GitHub, GitLab or Bitbucket. An account is only connected from here, never matched by email alone.')">
        <div class="grid gap-4 p-4 sm:p-6">
            @if ($errors->getBag('social')->any())
                <x-signal.ui.alert tone="danger" role="alert">{{ $errors->getBag('social')->first() }}</x-signal.ui.alert>
            @endif
            <ul class="grid gap-3" aria-label="{{ __('Sign-in providers') }}">
                @foreach ($providers as $row)
                    <li class="flex flex-wrap items-center justify-between gap-4 rounded-panel border border-line p-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-bold text-ink">{{ $row['provider']->label() }}</p>
                                <x-signal.ui.badge :tone="$row['identity'] ? 'success' : 'neutral'">{{ $row['identity'] ? __('Connected') : __('Not connected') }}</x-signal.ui.badge>
                            </div>
                            @if ($row['identity'])
                                <p class="mt-1 break-all text-xs leading-5 text-muted">
                                    {{ $row['identity']->email ?? __('No email shared') }} · {{ __('Connected :time', ['time' => $row['identity']->connectedAt?->diffForHumans() ?? __('recently')]) }}
                                </p>
                            @elseif (! $row['configured'])
                                <p class="mt-1 text-xs leading-5 text-muted">{{ __('Not available yet.') }}</p>
                            @endif
                        </div>
                        @if ($row['identity'])
                            <form method="POST" action="{{ route('social.disconnect', $row['provider']) }}">
                                @csrf
                                @method('DELETE')
                                <x-signal.ui.button type="submit" variant="quiet" :aria-label="__('Disconnect :provider', ['provider' => $row['provider']->label()])">{{ __('Disconnect') }}</x-signal.ui.button>
                            </form>
                        @elseif ($row['configured'])
                            <form method="POST" action="{{ route('social.connect', $row['provider']) }}">
                                @csrf
                                <x-signal.ui.button type="submit" variant="secondary">{{ __('Connect :provider', ['provider' => $row['provider']->label()]) }}</x-signal.ui.button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </x-signal.ui.settings-section>
</x-signal.layouts.settings>
