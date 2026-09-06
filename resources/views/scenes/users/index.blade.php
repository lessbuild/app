<x-layouts.app>
    <x-layouts.partials.heading
        icon="user-circle"
        :title="__('Account')"
        :description="__('Manage your profile and sign-in credentials.')"
    />

    <div class="mt-8 space-y-8">
        @if (! auth()->user()->hasVerifiedEmail())
            <div class="rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800">
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
                    <button type="submit" class="button primary">{{ __('Send verification email') }}</button>
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
                <div class="px-4 py-5 bg-primary space-y-6 sm:p-6">
                    @if (session('profile_status'))
                        <div class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700">
                            {{ session('profile_status') }}
                        </div>
                    @endif

                    <label class="block">
                        <span class="text-secondary text-sm pb-1 block">{{ __('Name') }}</span>
                        <input
                            class="input secondary rounded"
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
                            class="input secondary rounded"
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
                                class="input secondary rounded"
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
                        <button class="button primary" type="submit">{{ __('Save profile') }}</button>
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
                <div class="px-4 py-5 bg-primary space-y-6 sm:p-6">
                    @if (session('password_status'))
                        <div class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700">
                            {{ session('password_status') }}
                        </div>
                    @endif

                    @if (! auth()->user()->hasLocalPassword())
                        <p class="rounded border border-primary bg-secondary p-3 text-sm text-secondary">
                            {{ __('You signed in with :provider. Set a password here to also enable email and password sign-in.', ['provider' => ucfirst(auth()->user()->auth_type ?? 'a social provider')]) }}
                        </p>
                    @else
                        <label class="block">
                            <span class="text-secondary text-sm pb-1 block">{{ __('Current password') }}</span>
                            <input class="input secondary rounded" name="current_password" type="password" autocomplete="current-password" required>
                        </label>
                        <x-forms.errors name="current_password" bag="password" />
                    @endif

                    <label class="block">
                        <span class="text-secondary text-sm pb-1 block">{{ __('New password') }}</span>
                        <input class="input secondary rounded" name="password" type="password" autocomplete="new-password" required>
                    </label>
                    <x-forms.errors name="password" bag="password" />

                    <label class="block">
                        <span class="text-secondary text-sm pb-1 block">{{ __('Confirm new password') }}</span>
                        <input class="input secondary rounded" name="password_confirmation" type="password" autocomplete="new-password" required>
                    </label>
                </div>

                <x-slot:footer>
                    <div class="px-4 py-3 bg-tertiary text-right sm:px-6">
                        <button class="button primary" type="submit">{{ __('Update password') }}</button>
                    </div>
                </x-slot:footer>
            </x-forms.section>
        </form>

        <x-forms.section
            :title="__('Two-factor authentication')"
            :description="__('Require a rotating authenticator code after password or social sign-in.')"
        >
            <div class="space-y-5 bg-primary px-4 py-5 sm:p-6">
                @if (session('two_factor_status'))
                    <div class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700">{{ session('two_factor_status') }}</div>
                @endif

                @if (session('two_factor_recovery_codes'))
                    <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950">
                        <p class="font-bold">{{ __('Save these one-time recovery codes') }}</p>
                        <p class="mt-1 text-sm">{{ __('They will not be shown again. Store them somewhere separate from your authenticator app.') }}</p>
                        <div class="mt-4 grid gap-2 font-mono text-sm sm:grid-cols-2">
                            @foreach (session('two_factor_recovery_codes') as $recoveryCode)
                                <code class="rounded bg-white px-3 py-2">{{ $recoveryCode }}</code>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (auth()->user()->twoFactorEnabled())
                    <div class="rounded-xl border border-green-300 bg-green-50 p-4 text-green-800">
                        <p class="font-bold">{{ __('Two-factor authentication is active') }}</p>
                        <p class="mt-1 text-sm">{{ __('Every new sign-in requires your authenticator app or an unused recovery code.') }}</p>
                    </div>
                    <div class="grid gap-5 lg:grid-cols-2">
                        <form method="POST" action="{{ route('account.two-factor.recovery-codes') }}" class="space-y-3 rounded-xl border border-primary p-4">
                            @csrf
                            <h3 class="font-bold text-primary">{{ __('Replace recovery codes') }}</h3>
                            @if (auth()->user()->hasLocalPassword())
                                <input name="current_password" type="password" autocomplete="current-password" class="input secondary w-full rounded" placeholder="{{ __('Current password') }}" required>
                            @endif
                            <input name="code" autocomplete="one-time-code" class="input secondary w-full rounded font-mono" placeholder="{{ __('Authenticator or recovery code') }}" required>
                            <button type="submit" class="button primary">{{ __('Generate new codes') }}</button>
                        </form>
                        <form method="POST" action="{{ route('account.two-factor.disable') }}" class="space-y-3 rounded-xl border border-red-200 p-4">
                            @csrf @method('DELETE')
                            <h3 class="font-bold text-primary">{{ __('Disable two-factor authentication') }}</h3>
                            @if (auth()->user()->hasLocalPassword())
                                <input name="current_password" type="password" autocomplete="current-password" class="input secondary w-full rounded" placeholder="{{ __('Current password') }}" required>
                            @endif
                            <input name="code" autocomplete="one-time-code" class="input secondary w-full rounded font-mono" placeholder="{{ __('Authenticator or recovery code') }}" required>
                            <button type="submit" class="button secondary text-red-700">{{ __('Disable two-factor') }}</button>
                        </form>
                    </div>
                @elseif (filled(auth()->user()->two_factor_secret))
                    <div>
                        <h3 class="font-bold text-primary">{{ __('Connect your authenticator app') }}</h3>
                        <p class="mt-1 text-sm text-secondary">{{ __('Add this setup key manually, then enter the generated six-digit code.') }}</p>
                        <code class="mt-3 block break-all rounded-lg bg-secondary p-3 font-mono text-primary">{{ auth()->user()->two_factor_secret }}</code>
                        <details class="mt-3 text-sm text-secondary"><summary class="cursor-pointer font-semibold text-ternary">{{ __('Show provisioning URI') }}</summary><code class="mt-2 block break-all rounded bg-secondary p-3 text-xs">{{ $twoFactorProvisioningUri }}</code></details>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <form method="POST" action="{{ route('account.two-factor.confirm') }}" class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-end">
                            @csrf
                            <label class="block flex-1"><span class="block pb-1 text-sm text-secondary">{{ __('Six-digit code') }}</span><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="20" class="input secondary w-full rounded font-mono" required></label>
                            <button type="submit" class="button primary">{{ __('Confirm and enable') }}</button>
                        </form>
                        <form method="POST" action="{{ route('account.two-factor.cancel') }}">@csrf @method('DELETE')
                            <button type="submit" class="button secondary">{{ __('Cancel setup') }}</button>
                        </form>
                    </div>
                @else
                    <p class="text-sm leading-6 text-secondary">{{ __('Use any TOTP-compatible authenticator. You will receive eight one-time recovery codes after confirmation.') }}</p>
                    <form method="POST" action="{{ route('account.two-factor.enable') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        @csrf
                        @if (auth()->user()->hasLocalPassword())
                            <label class="block flex-1"><span class="block pb-1 text-sm text-secondary">{{ __('Current password') }}</span><input name="current_password" type="password" autocomplete="current-password" class="input secondary w-full rounded" required></label>
                        @endif
                        <button type="submit" class="button primary">{{ __('Set up authenticator') }}</button>
                    </form>
                @endif
                <x-forms.errors name="current_password" bag="twoFactor" />
                <x-forms.errors name="code" bag="twoFactor" />
            </div>
        </x-forms.section>

        <x-forms.section
            :title="__('Recent security activity')"
            :description="__('Review recent changes to your profile, credentials, sessions, and connected sign-in methods.')"
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
                        <a href="{{ route('activity.index', ['category' => 'account']) }}" class="button primary">
                            {{ __('View full account audit') }}
                        </a>
                    </div>
                </x-slot:footer>
            @endif
        </x-forms.section>

        <x-forms.section
            :title="__('Recent sign-ins')"
            :description="__('Review successful sign-ins retained for account security history.')"
        >
            <div class="divide-y divide-primary bg-primary">
                @if (session('sign_ins_status'))
                    <div class="m-4 rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700">
                        {{ session('sign_ins_status') }}
                    </div>
                @endif
                @forelse ($recentSignIns as $signIn)
                    <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-4 sm:px-6">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-primary">{{ $signIn['device'] }}</p>
                                <span class="rounded-full bg-secondary px-2 py-0.5 text-xs font-medium text-secondary">
                                    {{ $signIn['method'] }}
                                </span>
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
                        <a href="{{ route('account.sign-ins.index') }}" class="button primary">
                            {{ __('View full history') }}
                        </a>
                        <a href="{{ route('account.sign-ins.export') }}" class="button primary">
                            {{ __('Export CSV') }}
                        </a>
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
                                    class="input secondary rounded"
                                    name="current_password"
                                    type="password"
                                    autocomplete="current-password"
                                    required
                                >
                                <x-forms.errors name="current_password" bag="signIns" />
                            </label>
                            <button
                                type="submit"
                                class="button primary"
                                onclick="return confirm({{ Illuminate\Support\Js::from(__('Permanently clear your successful sign-in history?')) }})"
                            >
                                {{ __('Clear history') }}
                            </button>
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
            :title="__('Browser sessions')"
            :description="__('Review active browsers and log out sessions you no longer recognize.')"
        >
            <div class="px-4 py-5 bg-primary space-y-6 sm:p-6">
                @if (session('sessions_status'))
                    <div class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700">
                        {{ session('sessions_status') }}
                    </div>
                @endif
                @if (session('sessions_error'))
                    <div class="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                        {{ session('sessions_error') }}
                    </div>
                @endif

                @if ($browserSessionManagementAvailable)
                    <div class="divide-y divide-primary rounded border border-primary">
                        @forelse ($browserSessions as $browserSession)
                            <div class="flex flex-wrap items-start justify-between gap-4 p-4">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-medium text-primary">{{ $browserSession['device'] }}</p>
                                        @if ($browserSession['is_current'])
                                            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">
                                                {{ __('Current browser') }}
                                            </span>
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
                                                class="input secondary rounded"
                                                name="current_password"
                                                type="password"
                                                autocomplete="current-password"
                                                required
                                            >
                                            @if (old('session_id') === $browserSession['id'])
                                                <x-forms.errors name="current_password" bag="sessions" />
                                            @endif
                                        </label>
                                        <button
                                            type="submit"
                                            class="button primary"
                                            onclick="return confirm({{ Illuminate\Support\Js::from(__('Log out this browser session?')) }})"
                                        >
                                            {{ __('Log out') }}
                                        </button>
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
                                class="input secondary rounded"
                                name="current_password"
                                type="password"
                                autocomplete="current-password"
                                required
                            >
                        </label>
                        @if (blank(old('session_id')))
                            <x-forms.errors name="current_password" bag="sessions" />
                        @endif
                        <button class="button primary" type="submit">{{ __('Log out other sessions') }}</button>
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
            :title="__('Connected accounts')"
            :description="__('Review and disconnect social sign-in methods linked to your account.')"
        >
            <div class="divide-y divide-primary bg-primary">
                @if (session('social_status'))
                    <div class="m-4 rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700">
                        {{ session('social_status') }}
                    </div>
                @endif
                @if (session('social_error'))
                    <div class="m-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                        {{ session('social_error') }}
                    </div>
                @endif

                @foreach ($socialProviders as $provider)
                    <div class="flex flex-wrap items-center justify-between gap-4 px-4 py-5 sm:px-6">
                        <div>
                            <p class="font-medium text-primary">{{ $provider['name'] }}</p>
                            <p class="mt-1 text-sm text-secondary">
                                {{ $provider['connected'] ? __('Connected') : __('Not connected') }}
                            </p>
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
                                            class="input secondary rounded"
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
                                <button
                                    type="submit"
                                    class="button primary"
                                    onclick="return confirm({{ Illuminate\Support\Js::from(__('Disconnect :provider?', ['provider' => $provider['name']])) }})"
                                >
                                    {{ __('Disconnect') }}
                                </button>
                            </form>
                        @elseif ($provider['connected'])
                            <p class="max-w-sm text-right text-xs text-secondary">
                                {{ __('Set a local password before disconnecting your only sign-in method.') }}
                            </p>
                        @elseif ($provider['configured'])
                            <a href="{{ route('account.social.connect', $provider['key']) }}" class="button primary">
                                {{ __('Connect') }}
                            </a>
                        @else
                            <p class="text-xs text-secondary">{{ __('Not configured') }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-forms.section>

        <x-forms.section
            :title="__('Your data and account')"
            :description="__('Export your information or permanently delete your BuildPusher account.')"
        >
            <div class="space-y-6 bg-primary px-4 py-5 sm:p-6">
                <div class="flex flex-col gap-3 rounded-xl border border-primary p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div><h3 class="font-bold text-primary">{{ __('Export account data') }}</h3><p class="mt-1 text-sm text-secondary">{{ __('Download profile, workspace, infrastructure metadata, and sign-in records as JSON. Secrets are excluded.') }}</p></div>
                    <a href="{{ route('account.export') }}" class="button primary shrink-0">{{ __('Download export') }}</a>
                </div>
                <form method="POST" action="{{ route('account.destroy') }}" class="space-y-4 rounded-xl border border-red-200 bg-red-50 p-4">
                    @csrf @method('DELETE')
                    <div><h3 class="font-bold text-red-900">{{ __('Delete account and owned workspaces') }}</h3><p class="mt-1 text-sm leading-6 text-red-800">{{ __('This permanently removes BuildPusher control-plane data. It does not delete servers or resources in connected provider accounts. Remove teammates and wait for active operations first.') }}</p></div>
                    <label class="block"><span class="block pb-1 text-sm text-red-900">{{ __('Type your email address to confirm') }}</span><input name="confirmation" type="email" autocomplete="off" class="input secondary w-full rounded" required></label>
                    @if (auth()->user()->hasLocalPassword())
                        <label class="block"><span class="block pb-1 text-sm text-red-900">{{ __('Current password') }}</span><input name="current_password" type="password" autocomplete="current-password" class="input secondary w-full rounded" required></label>
                    @endif
                    @if (auth()->user()->twoFactorEnabled())
                        <label class="block"><span class="block pb-1 text-sm text-red-900">{{ __('Authenticator or recovery code') }}</span><input name="code" autocomplete="one-time-code" class="input secondary w-full rounded font-mono" required></label>
                    @endif
                    <x-forms.errors name="confirmation" bag="deleteAccount" />
                    <x-forms.errors name="current_password" bag="deleteAccount" />
                    <x-forms.errors name="code" bag="deleteAccount" />
                    <button type="submit" class="button secondary text-red-700" onclick="return confirm({{ Illuminate\Support\Js::from(__('Permanently delete your account and every workspace you own?')) }})">{{ __('Permanently delete account') }}</button>
                </form>
            </div>
        </x-forms.section>
    </div>
</x-layouts.app>
