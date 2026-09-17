<x-layouts.auth>
    <x-slot name="title">
        {{ __('Sign up for an account') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Sign up for an account to easily manage your work life.') }}

        @unless($invitation ?? null)
            <x-auth.social-providers action="up" />
        @else
            {{ __('This invitation is bound to the verified email address below.') }}
        @endunless
    </x-slot>

    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf
        @if ($invitationToken ?? null)
            <input type="hidden" name="invite" value="{{ $invitationToken }}">
        @endif

        <div>
            <label for="name" class="block text-sm font-semibold text-primary">{{ __('Name') }}</label>
            <input id="name" type="text" class="input secondary mt-2 rounded-lg" value="{{ old('name', $invitation?->name) }}" name="name" autocomplete="name" placeholder="{{ __('Ex: John Doe') }}">
            <x-forms.errors name="name" />
        </div>

        <div>
            <label for="email" class="block text-sm font-semibold text-primary">{{ __('Email') }}</label>
            <input id="email" type="email" class="input secondary mt-2 rounded-lg" value="{{ old('email', $invitation?->email) }}" name="email" autocomplete="email" @readonly($invitation ?? false) placeholder="{{ __('Ex: johndoe@mail.com') }}">
            <x-forms.errors name="email" />
        </div>

        <div>
            <label for="password" class="block text-sm font-semibold text-primary">{{ __('Password') }}</label>
            <input id="password" type="password" class="input secondary mt-2 rounded-lg" name="password" autocomplete="new-password" required>
            <x-forms.errors name="password" />
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-semibold text-primary">{{ __('Password Confirmation') }}</label>
            <input id="password_confirmation" type="password" class="input secondary mt-2 rounded-lg" name="password_confirmation" autocomplete="new-password" required>
            <x-forms.errors name="password_confirmation" />
        </div>

        <div class="flex flex-col gap-3 border-t border-primary pt-5 sm:flex-row sm:items-center sm:justify-end">
            <a class="text-sm text-secondary underline hover:text-primary" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-ui.button type="submit" variant="primary">
                {{ __('Register') }}
            </x-ui.button>
        </div>
    </form>

</x-layouts.auth>
