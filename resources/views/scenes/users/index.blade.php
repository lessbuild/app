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
                            <form method="POST" action="{{ route('account.social.destroy', $provider['key']) }}">
                                @csrf
                                @method('DELETE')
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
    </div>
</x-layouts.app>
