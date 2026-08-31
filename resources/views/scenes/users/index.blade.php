<x-layouts.app>
    <x-layouts.partials.heading
        icon="user-circle"
        :title="__('Account')"
        :description="__('Manage your profile and sign-in credentials.')"
    />

    <div class="mt-8 space-y-8">
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
    </div>
</x-layouts.app>
