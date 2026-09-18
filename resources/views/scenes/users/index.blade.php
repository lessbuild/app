<x-layouts.app>
    <x-layouts.partials.heading
        icon="user-circle"
        :title="__('Account')"
        :description="__('Manage your profile and sign-in credentials.')"
    />

    <div class="mt-8 max-w-5xl space-y-8">
        @if (! auth()->user()->hasVerifiedEmail())
            <div class="ui-alert ui-alert--warning p-4">
                <p class="font-semibold">{{ __('Verify your email') }}</p>
                <p class="mt-1">{{ __('Verify :email before managing infrastructure or deployments.', ['email' => auth()->user()->email]) }}</p>
                @if (session('status') === 'verification-link-sent')
                    <p class="mt-2 font-semibold">{{ __('A new verification link has been sent.') }}</p>
                @endif
                @if (session('verification_error'))
                    <p class="mt-2 font-semibold text-red-700">{{ session('verification_error') }}</p>
                @endif
                <form method="POST" action="{{ route('verification.send') }}" class="mt-3">
                    @csrf
                    <x-ui.button type="submit" variant="primary">{{ __('Send verification email') }}</x-ui.button>
                </form>
            </div>
        @endif

        <form method="POST" action="{{ route('account.profile.update') }}">
            @csrf
            @method('PATCH')

            <x-forms.section
                :title="__('Profile information')"
                :description="__('Update the name and email address associated with your account.')"
            >
                <div class="space-y-6 bg-primary px-4 py-5 sm:p-6">
                    @if (session('profile_status'))
                        <div class="ui-alert ui-alert--success p-3" role="status">
                            {{ session('profile_status') }}
                        </div>
                    @endif

                    <label class="block">
                        <span class="text-secondary text-sm pb-1 block">{{ __('Name') }}</span>
                        <input
                            class="input secondary rounded-lg"
                            name="name"
                            type="text"
                            autocomplete="name"
                            value="{{ old('name', auth()->user()->name) }}"
                            required
                        >
                    </label>
                    <x-forms.errors name="name" bag="profile" />

                    <label class="block">
                        <span class="text-secondary text-sm pb-1 block">{{ __('Email') }}</span>
                        <input
                            class="input secondary rounded-lg"
                            name="email"
                            type="email"
                            autocomplete="email"
                            value="{{ old('email', auth()->user()->email) }}"
                            required
                        >
                    </label>
                    <x-forms.errors name="email" bag="profile" />

                    @if (auth()->user()->hasLocalPassword())
                        <label class="block">
                            <span class="text-secondary text-sm pb-1 block">{{ __('Current password') }}</span>
                            <input
                                class="input secondary rounded-lg"
                                name="current_password"
                                type="password"
                                autocomplete="current-password"
                            >
                        </label>
                        <p class="text-sm text-secondary">
                            {{ __('Required only when changing your email address. Other browser sessions will be logged out after the change.') }}
                        </p>
                        <x-forms.errors name="current_password" bag="profile" />
                    @endif
                </div>

                <x-slot:footer>
                    <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                        <x-ui.button type="submit" variant="primary">{{ __('Save profile') }}</x-ui.button>
                    </div>
                </x-slot:footer>
            </x-forms.section>
        </form>

        <form id="password" method="POST" action="{{ route('account.password.update') }}">
            @csrf
            @method('PATCH')

            <x-forms.section
                :title="__('Update password')"
                :description="__('Use a long, unique password to keep your account secure.')"
            >
                <div class="space-y-6 bg-primary px-4 py-5 sm:p-6">
                    @if (session('password_status'))
                        <div class="ui-alert ui-alert--success p-3" role="status">
                            {{ session('password_status') }}
                        </div>
                    @endif

                    @if (! auth()->user()->hasLocalPassword())
                        <p class="ui-alert ui-alert--info p-3">
                            {{ __('You signed in with :provider. Set a password here to also enable email and password sign-in.', ['provider' => ucfirst(auth()->user()->auth_type ?? 'a social provider')]) }}
                        </p>
                    @else
                        <label class="block">
                            <span class="text-secondary text-sm pb-1 block">{{ __('Current password') }}</span>
                            <input class="input secondary rounded-lg" name="current_password" type="password" autocomplete="current-password" required>
                        </label>
                        <x-forms.errors name="current_password" bag="password" />
                    @endif

                    <label class="block">
                        <span class="text-secondary text-sm pb-1 block">{{ __('New password') }}</span>
                    <input class="input secondary rounded-lg" name="password" type="password" autocomplete="new-password" required>
                    </label>
                    <x-forms.errors name="password" bag="password" />

                    <label class="block">
                        <span class="text-secondary text-sm pb-1 block">{{ __('Confirm new password') }}</span>
                    <input class="input secondary rounded-lg" name="password_confirmation" type="password" autocomplete="new-password" required>
                    </label>
                </div>

                <x-slot:footer>
                    <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                        <x-ui.button type="submit" variant="primary">{{ __('Update password') }}</x-ui.button>
                    </div>
                </x-slot:footer>
            </x-forms.section>
        </form>

        <x-forms.section
            id="account-two-factor"
            :title="__('Two-factor authentication')"
            :description="__('Require a rotating authenticator code after password or social sign-in.')"
            :collapsible="true"
            :open="session('two_factor_status') || session('two_factor_recovery_codes') || filled(auth()->user()->two_factor_secret) || $errors->getBag('twoFactor')->any()"
        >
            <div class="space-y-5 bg-primary px-4 py-5 sm:p-6">
                @if (session('two_factor_status'))
                    <div class="ui-alert ui-alert--success p-3" role="status">{{ session('two_factor_status') }}</div>
                @endif

                @if (session('two_factor_recovery_codes'))
                    <div class="ui-alert ui-alert--warning p-4">
                        <p class="font-bold">{{ __('Save these one-time recovery codes') }}</p>
                        <p class="mt-1 text-sm">{{ __('They will not be shown again. Store them somewhere separate from your authenticator app.') }}</p>
                        <div class="mt-4 grid gap-2 font-mono text-sm sm:grid-cols-2">
                            @foreach (session('two_factor_recovery_codes') as $recoveryCode)
                            <code class="rounded-lg bg-white px-3 py-2 text-amber-950">{{ $recoveryCode }}</code>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (auth()->user()->twoFactorEnabled())
                    <div class="ui-alert ui-alert--success p-4">
                        <p class="font-bold">{{ __('Two-factor authentication is active') }}</p>
                        <p class="mt-1 text-sm">{{ __('Every new sign-in requires your authenticator app or an unused recovery code.') }}</p>
                    </div>
                    <div class="grid gap-5 lg:grid-cols-2">
                        <form method="POST" action="{{ route('account.two-factor.recovery-codes') }}" class="ui-card space-y-3 p-4">
                            @csrf
                            <h3 class="font-bold text-primary">{{ __('Replace recovery codes') }}</h3>
                            @if (auth()->user()->hasLocalPassword())
                                <input name="current_password" type="password" autocomplete="current-password" class="input secondary w-full rounded-lg" placeholder="{{ __('Current password') }}" required>
                            @endif
                            <input name="code" autocomplete="one-time-code" class="input secondary w-full rounded-lg font-mono" placeholder="{{ __('Authenticator or recovery code') }}" required>
                            <x-ui.button type="submit" variant="primary">{{ __('Generate new codes') }}</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('account.two-factor.disable') }}" class="ui-card space-y-3 border-red-200 p-4">
                            @csrf @method('DELETE')
                            <h3 class="font-bold text-primary">{{ __('Disable two-factor authentication') }}</h3>
                            @if (auth()->user()->hasLocalPassword())
                                <input name="current_password" type="password" autocomplete="current-password" class="input secondary w-full rounded-lg" placeholder="{{ __('Current password') }}" required>
                            @endif
                            <input name="code" autocomplete="one-time-code" class="input secondary w-full rounded-lg font-mono" placeholder="{{ __('Authenticator or recovery code') }}" required>
                            <x-ui.button type="submit" variant="danger">{{ __('Disable two-factor') }}</x-ui.button>
                        </form>
                    </div>
                @elseif (filled(auth()->user()->two_factor_secret))
                    <div>
                        <h3 class="font-bold text-primary">{{ __('Connect your authenticator app') }}</h3>
                        <p class="mt-1 text-sm text-secondary">{{ __('Add this setup key manually, then enter the generated six-digit code.') }}</p>
                        <code class="mt-3 block break-all rounded-lg bg-secondary p-3 font-mono text-primary">{{ auth()->user()->two_factor_secret }}</code>
                        <details class="mt-3 text-sm text-secondary"><summary class="cursor-pointer font-semibold text-ternary">{{ __('Show provisioning URI') }}</summary><code class="mt-2 block break-all rounded-lg bg-secondary p-3 text-xs">{{ $twoFactorProvisioningUri }}</code></details>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <form method="POST" action="{{ route('account.two-factor.confirm') }}" class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-end">
                            @csrf
                            <label class="block flex-1"><span class="block pb-1 text-sm text-secondary">{{ __('Six-digit code') }}</span><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="20" class="input secondary w-full rounded-lg font-mono" required></label>
                            <x-ui.button type="submit" variant="primary">{{ __('Confirm and enable') }}</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('account.two-factor.cancel') }}">@csrf @method('DELETE')
                            <x-ui.button type="submit" variant="secondary">{{ __('Cancel setup') }}</x-ui.button>
                        </form>
                    </div>
                @else
                    <p class="text-sm leading-6 text-secondary">{{ __('Use any TOTP-compatible authenticator. You will receive eight one-time recovery codes after confirmation.') }}</p>
                    <form method="POST" action="{{ route('account.two-factor.enable') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        @csrf
                        @if (auth()->user()->hasLocalPassword())
                            <label class="block flex-1"><span class="block pb-1 text-sm text-secondary">{{ __('Current password') }}</span><input name="current_password" type="password" autocomplete="current-password" class="input secondary w-full rounded-lg" required></label>
                        @endif
                        <x-ui.button type="submit" variant="primary">{{ __('Set up authenticator') }}</x-ui.button>
                    </form>
                @endif
                <x-forms.errors name="current_password" bag="twoFactor" />
                <x-forms.errors name="code" bag="twoFactor" />
            </div>
        </x-forms.section>

        <x-forms.section
            id="account-security-activity"
            :title="__('Recent security activity')"
            :description="__('Review recent changes to your profile, credentials, sessions, and connected sign-in methods.')"
            :collapsible="true"
        >
            <div class="bg-primary p-4 sm:p-6">
                <x-activity-feed
                    :events="$recentAccountEvents"
                    :empty-title="__('No security activity yet')"
                    :empty-description="__('Account security changes will appear here without credential, provider identity, session, or network details.')"
                />
            </div>

            @if (auth()->user()->hasVerifiedEmail())
                <x-slot:footer>
                    <div class="flex justify-end bg-tertiary px-4 py-3 sm:px-6">
                        <x-ui.button href="{{ route('activity.index', ['category' => 'account']) }}" variant="secondary">
                            {{ __('View full account audit') }}
                        </x-ui.button>
                    </div>
                </x-slot:footer>
            @endif
        </x-forms.section>

        <x-forms.section
            id="account-sign-ins"
            :title="__('Recent sign-ins')"
            :description="__('Review successful sign-ins retained for account security history.')"
            :collapsible="true"
            :open="session('sign_ins_status') || $errors->getBag('signIns')->any()"
        >
            <div class="divide-y divide-primary bg-primary">
                @if (session('sign_ins_status'))
                    <div class="ui-alert ui-alert--success m-4 p-3" role="status">
                        {{ session('sign_ins_status') }}
                    </div>
                @endif
                @forelse ($recentSignIns as $signIn)
                    <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-4 sm:px-6">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-primary">{{ $signIn['device'] }}</p>
                                <x-ui.badge tone="neutral">
                                    {{ $signIn['method'] }}
                                </x-ui.badge>
                            </div>
                            <p class="mt-1 text-sm text-secondary">{{ $signIn['ip_address'] }}</p>
                        </div>
                        <time
                            class="text-sm text-secondary"
                            datetime="{{ $signIn['signed_in_at']->toIso8601String() }}"
                            title="{{ $signIn['signed_in_at']->toDayDateTimeString() }}"
                        >
                            {{ $signIn['signed_in_at']->diffForHumans() }}
                        </time>
                    </div>
                @empty
                    <div class="p-6 text-center">
                        <p class="font-medium text-primary">{{ __('No sign-in history yet') }}</p>
                        <p class="mt-1 text-sm text-secondary">
                            {{ __('Successful password and social sign-ins will appear here.') }}
                        </p>
                    </div>
                @endforelse
            </div>

            <x-slot:footer>
                <div class="flex flex-wrap items-end justify-between gap-4 bg-tertiary px-4 py-3 sm:px-6">
                    <div class="flex flex-wrap gap-3">
                        <x-ui.button href="{{ route('account.sign-ins.index') }}" variant="secondary">
                            {{ __('View full history') }}
                        </x-ui.button>
                        <x-ui.button href="{{ route('account.sign-ins.export') }}" variant="secondary">
                            {{ __('Export CSV') }}
                        </x-ui.button>
                    </div>

                    @if ($recentSignIns->isNotEmpty() && auth()->user()->hasLocalPassword())
                        <form method="POST" action="{{ route('account.sign-ins.destroy') }}" class="flex flex-wrap items-end justify-end gap-3">
                            @csrf
                            @method('DELETE')
                            <label class="block min-w-52 text-left">
                                <span class="block pb-1 text-xs font-medium text-secondary">
                                    {{ __('Current password') }}
                                </span>
                                <input
                                    class="input secondary rounded-lg"
                                    name="current_password"
                                    type="password"
                                    autocomplete="current-password"
                                    required
                                >
                                <x-forms.errors name="current_password" bag="signIns" />
                            </label>
                            <x-ui.button
                                type="submit"
                                variant="danger"
                                onclick="return confirm({{ Illuminate\Support\Js::from(__('Permanently clear your successful sign-in history?')) }})"
                            >
                                {{ __('Clear history') }}
                            </x-ui.button>
                        </form>
                    @elseif ($recentSignIns->isNotEmpty())
                        <p class="text-sm text-secondary">
                            {{ __('Set a local password before clearing sign-in history.') }}
                            <a href="#password" class="font-medium text-ternary underline">{{ __('Set password') }}</a>
                        </p>
                    @endif
                </div>
            </x-slot:footer>
        </x-forms.section>

        <x-forms.section
            id="account-browser-sessions"
            :title="__('Browser sessions')"
            :description="__('Review active browsers and log out sessions you no longer recognize.')"
            :collapsible="true"
            :open="session('sessions_status') || session('sessions_error') || old('session_id') || $errors->getBag('sessions')->any()"
        >
            <div class="space-y-6 bg-primary px-4 py-5 sm:p-6">
                @if (session('sessions_status'))
                    <div class="ui-alert ui-alert--success p-3" role="status">
                        {{ session('sessions_status') }}
                    </div>
                @endif
                @if (session('sessions_error'))
                    <div class="ui-alert ui-alert--danger p-3" role="alert">
                        {{ session('sessions_error') }}
                    </div>
                @endif

                @if ($browserSessionManagementAvailable)
                    <div class="ui-card divide-y divide-primary overflow-hidden">
                        @forelse ($browserSessions as $browserSession)
                            <div class="flex flex-wrap items-start justify-between gap-4 p-4">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-medium text-primary">{{ $browserSession['device'] }}</p>
                                        @if ($browserSession['is_current'])
                                            <x-ui.badge tone="success">
                                                {{ __('Current browser') }}
                                            </x-ui.badge>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm text-secondary">
                                        {{ $browserSession['ip_address'] }}
                                        <span aria-hidden="true">&middot;</span>
                                        <span title="{{ $browserSession['last_active_at']->toIso8601String() }}">
                                            {{ __('Active :time', ['time' => $browserSession['last_active_at']->diffForHumans()]) }}
                                        </span>
                                    </p>
                                </div>

                                @if (! $browserSession['is_current'] && auth()->user()->hasLocalPassword())
                                    <form method="POST" action="{{ route('account.sessions.destroy', $browserSession['id']) }}" class="flex flex-wrap items-end justify-end gap-3">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="session_id" value="{{ $browserSession['id'] }}">
                                        <label class="block min-w-52 text-left">
                                            <span class="block pb-1 text-xs font-medium text-secondary">
                                                {{ __('Current password') }}
                                            </span>
                                            <input
                                                class="input secondary rounded-lg"
                                                name="current_password"
                                                type="password"
                                                autocomplete="current-password"
                                                required
                                            >
                                            @if (old('session_id') === $browserSession['id'])
                                                <x-forms.errors name="current_password" bag="sessions" />
                                            @endif
                                        </label>
                                        <x-ui.button
                                            type="submit"
                                            variant="danger"
                                            onclick="return confirm({{ Illuminate\Support\Js::from(__('Log out this browser session?')) }})"
                                        >
                                            {{ __('Log out') }}
                                        </x-ui.button>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <p class="p-4 text-sm text-secondary">
                                {{ __('No active database-backed browser sessions were found.') }}
                            </p>
                        @endforelse
                    </div>
                    @if ($browserSessions->count() === App\Services\BrowserSessionManager::MAX_VISIBLE_SESSIONS)
                        <p class="text-xs text-secondary">
                            {{ __('Showing the 20 most recently active sessions. Use the control below to log out every other session.') }}
                        </p>
                    @endif
                @endif

                @if (auth()->user()->hasLocalPassword())
                    <form method="POST" action="{{ route('account.sessions.revoke') }}" class="space-y-6 border-t border-primary pt-6">
                        @csrf
                        <label class="block">
                            <span class="text-secondary text-sm pb-1 block">{{ __('Current password') }}</span>
                            <input
                                class="input secondary rounded-lg"
                                name="current_password"
                                type="password"
                                autocomplete="current-password"
                                required
                            >
                        </label>
                        @if (blank(old('session_id')))
                            <x-forms.errors name="current_password" bag="sessions" />
                        @endif
                        <x-ui.button type="submit" variant="primary">{{ __('Log out other sessions') }}</x-ui.button>
                    </form>
                @else
                    <p class="text-sm text-secondary">
                        {{ __('Set a local password before revoking other browser sessions.') }}
                        <a href="#password" class="font-medium text-ternary underline">{{ __('Set password') }}</a>
                    </p>
                @endif
            </div>
        </x-forms.section>

        <x-forms.section
            id="account-connected-accounts"
            :title="__('Connected accounts')"
            :description="__('Review and disconnect social sign-in methods linked to your account.')"
            :collapsible="true"
            :open="session('social_status') || session('social_error') || $errors->getBag('social')->any()"
        >
            <div class="divide-y divide-primary bg-primary">
                @if (session('social_status'))
                    <x-ui.alert tone="success" class="m-4" role="status">
                        {{ session('social_status') }}
                    </x-ui.alert>
                @endif
                @if (session('social_error'))
                    <div class="ui-alert ui-alert--danger m-4 p-3" role="alert">
                        {{ session('social_error') }}
                    </div>
                @endif

                @foreach ($socialProviders as $provider)
                    <div class="flex flex-wrap items-center justify-between gap-4 px-4 py-5 sm:px-6">
                        <div>
                            <p class="font-medium text-primary">{{ $provider['name'] }}</p>
                            <div class="mt-2">
                                <x-ui.badge :tone="$provider['connected'] ? 'success' : 'neutral'">
                                    {{ $provider['connected'] ? __('Connected') : __('Not connected') }}
                                </x-ui.badge>
                            </div>
                        </div>
                        @if ($provider['connected'] && $provider['can_disconnect'])
                            <form method="POST" action="{{ route('account.social.destroy', $provider['key']) }}" class="flex flex-wrap items-end justify-end gap-3">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="social_provider" value="{{ $provider['key'] }}">
                                @if ($provider['requires_password'])
                                    <label class="block min-w-52 text-left">
                                        <span class="block pb-1 text-xs font-medium text-secondary">
                                            {{ __('Current password') }}
                                        </span>
                                        <input
                                            class="input secondary rounded-lg"
                                            name="current_password"
                                            type="password"
                                            autocomplete="current-password"
                                            required
                                        >
                                        @if (old('social_provider') === $provider['key'])
                                            <x-forms.errors name="current_password" bag="social" />
                                        @endif
                                    </label>
                                @endif
                                <x-ui.button
                                    type="submit"
                                    variant="danger"
                                    onclick="return confirm({{ Illuminate\Support\Js::from(__('Disconnect :provider?', ['provider' => $provider['name']])) }})"
                                >
                                    {{ __('Disconnect') }}
                                </x-ui.button>
                            </form>
                        @elseif ($provider['connected'])
                            <p class="max-w-sm text-right text-xs text-secondary">
                                {{ __('Set a local password before disconnecting your only sign-in method.') }}
                            </p>
                        @elseif ($provider['configured'])
                            <x-ui.button href="{{ route('account.social.connect', $provider['key']) }}" variant="secondary">
                                {{ __('Connect') }}
                            </x-ui.button>
                        @else
                            <p class="text-xs text-secondary">{{ __('Not configured') }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-forms.section>

        <x-forms.section
            id="account-data"
            :title="__('Your data and account')"
            :description="__('Export your information or permanently delete your BuildPusher account.')"
            :collapsible="true"
            :open="$errors->getBag('deleteAccount')->any()"
        >
            <div class="space-y-6 bg-primary px-4 py-5 sm:p-6">
                <div class="ui-card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div><h3 class="font-bold text-primary">{{ __('Export account data') }}</h3><p class="mt-1 text-sm text-secondary">{{ __('Download profile, workspace, infrastructure metadata, and sign-in records as JSON. Secrets are excluded.') }}</p></div>
                    <x-ui.button href="{{ route('account.export') }}" variant="secondary" class="shrink-0">{{ __('Download export') }}</x-ui.button>
                </div>
                <form method="POST" action="{{ route('account.destroy') }}" class="ui-card space-y-4 border-red-200 bg-red-50 p-4">
                    @csrf @method('DELETE')
                    <div><h3 class="font-bold text-red-900">{{ __('Delete account and owned workspaces') }}</h3><p class="mt-1 text-sm leading-6 text-red-800">{{ __('This permanently removes BuildPusher control-plane data. It does not delete servers or resources in connected provider accounts. Remove teammates and wait for active operations first.') }}</p></div>
                    <label class="block"><span class="block pb-1 text-sm text-red-900">{{ __('Type your email address to confirm') }}</span><input name="confirmation" type="email" autocomplete="off" class="input secondary w-full rounded-lg" required></label>
                    @if (auth()->user()->hasLocalPassword())
                        <label class="block"><span class="block pb-1 text-sm text-red-900">{{ __('Current password') }}</span><input name="current_password" type="password" autocomplete="current-password" class="input secondary w-full rounded-lg" required></label>
                    @endif
                    @if (auth()->user()->twoFactorEnabled())
                        <label class="block"><span class="block pb-1 text-sm text-red-900">{{ __('Authenticator or recovery code') }}</span><input name="code" autocomplete="one-time-code" class="input secondary w-full rounded-lg font-mono" required></label>
                    @endif
                    <x-forms.errors name="confirmation" bag="deleteAccount" />
                    <x-forms.errors name="current_password" bag="deleteAccount" />
                    <x-forms.errors name="code" bag="deleteAccount" />
                    <x-ui.button type="submit" variant="danger" onclick="return confirm({{ Illuminate\Support\Js::from(__('Permanently delete your account and every workspace you own?')) }})">{{ __('Permanently delete account') }}</x-ui.button>
                </form>
            </div>
        </x-forms.section>
    </div>
</x-layouts.app>
