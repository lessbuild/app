<x-signal.layouts.core
    :title="__('Account security')"
    :description="__('Manage the sign-in methods and browser sessions for your Buildpusher account.')"
    product-key="core"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.layouts.topbar
        product-key="core"
        :navigation="[]"
        :brand-url="route('core.home')"
        :projects-url="route('core.home')"
        :account-user="$user"
        logout-route="platform.logout"
        :show-notifications="false"
        :show-project-context="false"
        :show-environment-context="false"
    />

    <main id="main-content" tabindex="-1" class="ui-layout-gutter mx-auto w-full max-w-content space-y-7 py-8">
        <x-signal.ui.page-header
            :eyebrow="__('Account')"
            :title="__('Account security')"
            :description="__('Manage the password, authenticator, recovery codes, and signed-in browsers for :email.', ['email' => $user->email])"
        />

        @if (session('security_status'))
            <x-signal.ui.alert tone="success" role="status">{{ session('security_status') }}</x-signal.ui.alert>
        @endif
        @if (session('password_status'))
            <x-signal.ui.alert tone="success" role="status">{{ session('password_status') }}</x-signal.ui.alert>
        @endif
        @if ($errors->has('session'))
            <x-signal.ui.alert tone="warning" role="alert">{{ $errors->first('session') }}</x-signal.ui.alert>
        @endif

        @if (is_array($recoveryCodes) && $recoveryCodes !== [])
            <x-signal.ui.panel as="section" class="space-y-4 border-warning p-6" aria-labelledby="recovery-codes-heading">
                <div>
                    <p class="ui-eyebrow">{{ __('Save these now') }}</p>
                    <h2 id="recovery-codes-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Recovery codes') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-muted">{{ __('Each code works once. Store them somewhere private; leaving this page hides them.') }}</p>
                </div>
                <ul class="grid gap-2 font-mono text-sm sm:grid-cols-2" aria-label="{{ __('One-time recovery codes') }}">
                    @foreach ($recoveryCodes as $recoveryCode)
                        <li class="rounded-control border border-line bg-surface-muted px-3 py-2 text-ink">{{ $recoveryCode }}</li>
                    @endforeach
                </ul>
            </x-signal.ui.panel>
        @endif

        <x-signal.ui.panel as="section" class="space-y-5 p-6" aria-labelledby="password-heading">
            <div>
                <p class="ui-eyebrow">{{ __('Password') }}</p>
                <h2 id="password-heading" class="mt-1 text-lg font-extrabold text-ink">{{ $hasPassword ? __('Change your password') : __('Set a password') }}</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('Changing your password signs out other browsers while keeping this browser signed in.') }}</p>
            </div>
            <form method="POST" action="{{ route('platform.account.password.update') }}" class="grid gap-4 sm:max-w-xl">
                @csrf
                @if ($hasPassword)
                <x-signal.ui.input-field name="current_password" :label="__('Current password')" type="password" required autocomplete="current-password" error-bag="password" />
                @elseif ($user->twoFactorEnabled())
                    <x-signal.ui.input-field name="code" :label="__('Authenticator or recovery code')" type="text" required autocomplete="one-time-code" inputmode="text" error-bag="password" />
                @endif
                <x-signal.ui.input-field name="password" :label="__('New password')" type="password" required autocomplete="new-password" error-bag="password" />
                <x-signal.ui.input-field name="password_confirmation" :label="__('Confirm new password')" type="password" required autocomplete="new-password" error-bag="password" />
                <div><x-signal.ui.button type="submit" variant="primary">{{ $hasPassword ? __('Update password') : __('Set password') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.panel>

        <x-signal.ui.panel as="section" class="space-y-5 p-6" aria-labelledby="two-factor-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Authenticator app') }}</p>
                    <h2 id="two-factor-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Two-factor authentication') }}</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-muted">
                        {{ $user->twoFactorEnabled() ? __('An authenticator code is required when this account signs in.') : __('Add a second sign-in step with an authenticator app. Existing authenticator settings carried over from a product continue to work here.') }}
                    </p>
                </div>
                <x-signal.ui.badge :tone="$user->twoFactorEnabled() ? 'success' : 'neutral'">{{ $user->twoFactorEnabled() ? __('Enabled') : __('Not enabled') }}</x-signal.ui.badge>
            </div>

            @if ($user->twoFactorEnabled())
                <div class="flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('platform.account.recovery-codes.regenerate') }}" class="grid w-full gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:max-w-xl">
                        @csrf
                        @if ($hasPassword)
                            <x-signal.ui.input-field name="current_password" :label="__('Current password')" type="password" required autocomplete="current-password" error-bag="twoFactor" />
                        @endif
                        <x-signal.ui.input-field name="code" :label="__('Authenticator or recovery code')" type="text" required autocomplete="one-time-code" error-bag="twoFactor" />
                        <div><x-signal.ui.button type="submit" variant="secondary">{{ __('Create new recovery codes') }}</x-signal.ui.button></div>
                    </form>
                </div>
                <form method="POST" action="{{ route('platform.account.two-factor.disable') }}" class="grid gap-4 rounded-panel border border-line p-4 sm:max-w-xl">
                    @csrf
                    @method('DELETE')
                    @if ($hasPassword)
                        <x-signal.ui.input-field name="current_password" :label="__('Current password')" type="password" required autocomplete="current-password" error-bag="twoFactor" />
                    @endif
                    <x-signal.ui.input-field name="code" :label="__('Authenticator or recovery code')" type="text" required autocomplete="one-time-code" error-bag="twoFactor" />
                    <div><x-signal.ui.button type="submit" variant="danger">{{ __('Turn off two-factor authentication') }}</x-signal.ui.button></div>
                </form>
            @elseif ($pendingSecret !== null)
                <div class="grid gap-4 rounded-panel border border-line bg-surface-muted p-4">
                    <p class="text-sm leading-6 text-muted">{{ __('Add this account to your authenticator app. You can scan the URI with a compatible app or enter the secret manually.') }}</p>
                    <div>
                        <x-signal.ui.input-field id="platform-two-factor-secret" name="platform_two_factor_secret" :label="__('Authenticator secret')" :value="$pendingSecret" readonly :restore="false" :error-key="false" />
                    </div>
                    <details class="text-sm">
                        <summary class="cursor-pointer font-bold text-ink focus-visible:outline-2 focus-visible:outline-focus">{{ __('Show setup URI') }}</summary>
                        <code class="mt-2 block break-all rounded-control border border-line bg-surface px-3 py-2 font-mono text-xs text-ink">{{ $provisioningUri }}</code>
                    </details>
                    <form method="POST" action="{{ route('platform.account.two-factor.confirm') }}" class="grid gap-4 sm:max-w-md">
                        @csrf
                        <x-signal.ui.input-field name="code" :label="__('Current authenticator code')" type="text" required autocomplete="one-time-code" inputmode="numeric" error-bag="twoFactor" />
                        <div class="flex flex-wrap gap-3">
                            <x-signal.ui.button type="submit" variant="primary">{{ __('Confirm and enable') }}</x-signal.ui.button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('platform.account.two-factor.cancel') }}">
                        @csrf
                        <x-signal.ui.button type="submit" variant="quiet">{{ __('Cancel setup') }}</x-signal.ui.button>
                    </form>
                </div>
            @else
                <form method="POST" action="{{ route('platform.account.two-factor.begin') }}" class="grid gap-4 sm:max-w-md">
                    @csrf
                    @if ($hasPassword)
                        <x-signal.ui.input-field name="current_password" :label="__('Current password')" type="password" required autocomplete="current-password" error-bag="twoFactor" />
                    @endif
                    <div><x-signal.ui.button type="submit" variant="primary">{{ __('Set up authenticator') }}</x-signal.ui.button></div>
                </form>
            @endif
        </x-signal.ui.panel>

        <x-signal.ui.panel as="section" class="space-y-5 p-6" aria-labelledby="social-accounts-heading">
            <div>
                <p class="ui-eyebrow">{{ __('Other sign-in methods') }}</p>
                <h2 id="social-accounts-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Connected accounts') }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-muted">{{ __('Connect a verified GitHub, GitLab, or Bitbucket identity to use the same Buildpusher account across every product. An email match alone never connects two accounts.') }}</p>
            </div>

            @if (session('social_status'))
                <x-signal.ui.alert tone="success" role="status">{{ session('social_status') }}</x-signal.ui.alert>
            @endif
            @if (session('social_error'))
                <x-signal.ui.alert tone="danger" role="alert">{{ session('social_error') }}</x-signal.ui.alert>
            @endif
            @if ($errors->getBag('social')->any())
                <x-signal.ui.alert tone="danger" role="alert">{{ $errors->getBag('social')->first() }}</x-signal.ui.alert>
            @endif

            <ul class="grid gap-3">
                @foreach ($socialProviders as $provider)
                    <li class="grid gap-4 rounded-panel border border-line p-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.85fr)] lg:items-center">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-bold text-ink">{{ $provider['name'] }}</h3>
                                <x-signal.ui.badge :tone="$provider['connected'] ? 'success' : 'neutral'">
                                    {{ $provider['connected'] ? __('Connected') : __('Not connected') }}
                                </x-signal.ui.badge>
                            </div>
                            @if ($provider['connected'])
                                <p class="mt-2 break-all text-xs leading-5 text-muted">{{ $provider['email'] ?: __('Verified email unavailable') }}</p>
                                <p class="mt-1 text-xs text-subtle">{{ __('Connected :time', ['time' => $provider['connected_at']?->diffForHumans() ?? __('recently')]) }}</p>
                            @elseif (! $provider['configured'])
                                <p class="mt-2 text-xs leading-5 text-muted">{{ __('This sign-in provider is not configured yet.') }}</p>
                            @endif
                        </div>

                        @if ($provider['connected'])
                            <form method="POST" action="{{ route('platform.account.social.disconnect', $provider['key']) }}" class="grid gap-3 rounded-panel border border-line bg-surface-muted p-3 sm:grid-cols-2">
                                @csrf
                                @method('DELETE')
                                <x-signal.ui.input type="hidden" name="social_provider" :value="$provider['key']" :restore="false" />
                                @if ($hasPassword)
                                    <x-signal.ui.input-field :id="'social-'.$provider['key'].'-current-password'" :error-key="old('social_provider') === $provider['key'] ? 'current_password' : false" name="current_password" :label="__('Current password')" type="password" required autocomplete="current-password" error-bag="social" />
                                @endif
                                @if ($user->twoFactorEnabled())
                                    <x-signal.ui.input-field :id="'social-'.$provider['key'].'-disconnect-code'" :error-key="old('social_provider') === $provider['key'] ? 'code' : false" name="code" :label="__('Authenticator or recovery code')" type="text" required autocomplete="one-time-code" error-bag="social" />
                                @endif
                                <div class="sm:col-span-2">
                                    <x-signal.ui.button type="submit" variant="quiet" onclick="return confirm({{ Illuminate\Support\Js::from(__('Disconnect :provider?', ['provider' => $provider['name']])) }})">{{ __('Disconnect') }}</x-signal.ui.button>
                                </div>
                            </form>
                        @elseif ($provider['configured'])
                            <form method="POST" action="{{ route('platform.account.social.connect', $provider['key']) }}" class="grid gap-3 rounded-panel border border-line bg-surface-muted p-3 sm:grid-cols-2">
                                @csrf
                                <x-signal.ui.input type="hidden" name="social_provider" :value="$provider['key']" :restore="false" />
                                @if ($hasPassword)
                                    <x-signal.ui.input-field :id="'social-'.$provider['key'].'-connect-password'" :error-key="old('social_provider') === $provider['key'] ? 'current_password' : false" name="current_password" :label="__('Current password')" type="password" required autocomplete="current-password" error-bag="social" />
                                @endif
                                @if ($user->twoFactorEnabled())
                                    <x-signal.ui.input-field :id="'social-'.$provider['key'].'-connect-code'" :error-key="old('social_provider') === $provider['key'] ? 'code' : false" name="code" :label="__('Authenticator or recovery code')" type="text" required autocomplete="one-time-code" error-bag="social" />
                                @endif
                                <div class="sm:col-span-2">
                                    <x-signal.ui.button type="submit" variant="secondary">{{ __('Connect :provider', ['provider' => $provider['name']]) }}</x-signal.ui.button>
                                </div>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.panel>

        <x-signal.ui.panel as="section" class="space-y-5 p-6" aria-labelledby="passkeys-heading" data-passkey-surface>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Passwordless sign-in') }}</p>
                    <h2 id="passkeys-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Passkeys') }}</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-muted">{{ __('Use a device screen lock, biometric, or security key to sign in across your Buildpusher subdomains.') }}</p>
                </div>
                <x-signal.ui.badge :tone="$passkeys->isEmpty() ? 'neutral' : 'success'">{{ trans_choice(':count passkey|:count passkeys', $passkeys->count(), ['count' => $passkeys->count()]) }}</x-signal.ui.badge>
            </div>

            @if ($errors->getBag('passkeys')->has('passkey_name'))
                <x-signal.ui.alert tone="danger" role="alert">{{ $errors->getBag('passkeys')->first('passkey_name') }}</x-signal.ui.alert>
            @endif
            <p data-passkey-status role="status" aria-live="polite" class="min-h-5 text-sm text-muted"></p>

            @if ($passkeys->isEmpty())
                <x-signal.ui.empty-state :title="__('No passkeys yet')" :description="__('Add a passkey so you can sign in without entering your password.')" />
            @else
                <ul class="grid gap-3" aria-label="{{ __('Registered passkeys') }}">
                    @foreach ($passkeys as $passkey)
                        <li class="grid gap-4 rounded-panel border border-line p-4 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,0.8fr)] lg:items-center">
                            <div class="min-w-0">
                                <p class="break-words text-sm font-bold text-ink">{{ $passkey['name'] }}</p>
                                <p class="mt-1 text-xs leading-5 text-muted">
                                    {{ $passkey['authenticator'] ?: __('Authenticator details unavailable') }}
                                    · {{ __('Added :time', ['time' => $passkey['created_at']?->diffForHumans() ?? __('recently')]) }}
                                </p>
                                <p class="mt-1 text-xs text-subtle">{{ __('Last used :time', ['time' => $passkey['last_used_at']?->diffForHumans() ?? __('never')]) }}</p>
                            </div>
                            <form method="POST" action="{{ route('platform.account.passkeys.destroy', $passkey['id']) }}" class="grid gap-3 rounded-panel border border-line bg-surface-muted p-3 sm:grid-cols-2">
                                @csrf
                                @method('DELETE')
                                @if ($hasPassword)
                                    <x-signal.ui.input-field name="current_password" :label="__('Current password')" type="password" required autocomplete="current-password" error-bag="passkeys" />
                                @endif
                                @if ($user->twoFactorEnabled())
                                    <x-signal.ui.input-field name="code" :label="__('Authenticator or recovery code')" type="text" required autocomplete="one-time-code" error-bag="passkeys" />
                                @endif
                                <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="quiet">{{ __('Remove passkey') }}</x-signal.ui.button></div>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form
                method="POST"
                action="{{ route('platform.account.passkeys.store') }}"
                data-passkey-registration
                data-options-url="{{ route('platform.account.passkeys.options') }}"
                data-working-message="{{ __('Follow your device prompts to add a passkey…') }}"
                data-failed-message="{{ __('Passkey registration could not be completed. Please try again.') }}"
                data-unsupported-message="{{ __('This browser does not support passkeys.') }}"
                class="grid gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:max-w-xl"
            >
                @csrf
                <x-signal.ui.input-field name="name" :label="__('Passkey name')" :description="__('Choose a name you will recognize, such as “Work laptop”.')" required maxlength="255" autocomplete="off" error-bag="passkeys" />
                @if ($hasPassword)
                    <x-signal.ui.input-field name="current_password" :label="__('Current password')" type="password" required autocomplete="current-password" error-bag="passkeys" />
                @endif
                @if ($user->twoFactorEnabled())
                    <x-signal.ui.input-field name="code" :label="__('Authenticator or recovery code')" type="text" required autocomplete="one-time-code" error-bag="passkeys" />
                @endif
                <div><x-signal.ui.button type="submit" variant="primary">{{ __('Add a passkey') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.panel>

        <x-signal.ui.panel as="section" class="space-y-5 p-6" aria-labelledby="sessions-heading">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __('Active access') }}</p>
                    <h2 id="sessions-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Signed-in browsers') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-muted">{{ __('Signing out a browser revokes its Buildpusher session across product subdomains.') }}</p>
                </div>
                @if ($activeSessions->count() > 1)
                    <form method="POST" action="{{ route('platform.account.sessions.revoke-others') }}">
                        @csrf
                        <x-signal.ui.button type="submit" variant="secondary">{{ __('Log out other browsers') }}</x-signal.ui.button>
                    </form>
                @endif
            </div>
            @if ($activeSessions->isEmpty())
                <x-signal.ui.empty-state :title="__('No active browser sessions')" :description="__('Your current sign-in will appear here after the next request.')" />
            @else
                <ul class="grid gap-3" aria-label="{{ __('Active browser sessions') }}">
                    @foreach ($activeSessions as $session)
                        <li class="flex flex-wrap items-center justify-between gap-4 rounded-panel border border-line p-4">
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-ink">
                                    {{ (string) $session->getKey() === $currentSessionId ? __('This browser') : ($session->remembered ? __('Remembered browser') : __('Signed-in browser')) }}
                                </p>
                                <p class="mt-1 break-all text-xs leading-5 text-muted">{{ $session->user_agent ?: __('Browser details unavailable') }}</p>
                                <p class="mt-1 text-xs text-subtle">
                                    {{ __('Last active :time', ['time' => ($session->last_seen_at ?? $session->created_at)?->diffForHumans() ?? __('unknown')]) }}
                                    @if ($session->ip_address)
                                        · {{ __('IP :address', ['address' => $session->ip_address]) }}
                                    @endif
                                </p>
                            </div>
                            @if ((string) $session->getKey() !== $currentSessionId)
                                <form method="POST" action="{{ route('platform.account.sessions.revoke', $session) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-signal.ui.button type="submit" variant="quiet">{{ __('Log out') }}</x-signal.ui.button>
                                </form>
                            @else
                                <x-signal.ui.badge tone="success">{{ __('Current') }}</x-signal.ui.badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-signal.ui.panel>

        <x-signal.ui.panel as="section" class="flex flex-wrap items-center justify-between gap-5 p-6" aria-labelledby="account-export-heading">
            <div class="max-w-3xl">
                <p class="ui-eyebrow">{{ __('Data and privacy') }}</p>
                <h2 id="account-export-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Download your shared account data') }}</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ __('Export your Buildpusher profile, sign-in metadata, workspace and project access, personal dashboard settings, notification preferences, and feedback. Product operational data remains available from that product’s export tools.') }}</p>
            </div>
            <x-signal.ui.button href="{{ route('platform.account.export') }}" variant="secondary">{{ __('Download account export') }}</x-signal.ui.button>
        </x-signal.ui.panel>

        <x-signal.ui.panel as="section" class="space-y-2 border-l-4 border-l-warning p-6" aria-labelledby="account-deletion-heading">
            <p class="ui-eyebrow">{{ __('Data and privacy') }}</p>
            <h2 id="account-deletion-heading" class="text-lg font-extrabold text-ink">{{ __('Account deletion is not available yet') }}</h2>
            <p class="text-sm leading-6 text-muted">{{ __('Buildpusher accounts can own projects and data across Deployer, Monitor, and Analytics. Deletion must coordinate cleanup and retention rules with all three products before any records are removed. Until that workflow is ready, your account remains active and unchanged. Product-local deletion is blocked while shared authentication is enabled.') }}</p>
        </x-signal.ui.panel>
    </main>
    @vite('resources/js/platform-passkeys.js')
</x-signal.layouts.core>
