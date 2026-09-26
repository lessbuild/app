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
            <x-signal.ui.input type="hidden" name="invite" value="{{ $invitationToken }}" :restore="false" />
        @endif

        <div>
            <label for="name" class="ui-label">{{ __('Name') }}</label>
            <x-signal.ui.input id="name" type="text" class="ui-input" value="{{ old('name', $invitation?->name) }}" name="name" autocomplete="name" placeholder="{{ __('Ex: John Doe') }}" :restore="false" />
            <x-forms.errors name="name" />
        </div>

        <div>
            <label for="email" class="ui-label">{{ __('Email') }}</label>
            <x-signal.ui.input id="email" type="email" class="ui-input" value="{{ old('email', $invitation?->email) }}" name="email" autocomplete="email" @readonly($invitation ?? false) placeholder="{{ __('Ex: johndoe@mail.com') }}" :restore="false" />
            <x-forms.errors name="email" />
        </div>

        <div>
            <label for="password" class="ui-label">{{ __('Password') }}</label>
            <x-signal.ui.input id="password" type="password" class="ui-input" name="password" autocomplete="new-password" required :restore="false" />
            <x-forms.errors name="password" />
        </div>

        <div>
            <label for="password_confirmation" class="ui-label">{{ __('Password Confirmation') }}</label>
            <x-signal.ui.input id="password_confirmation" type="password" class="ui-input" name="password_confirmation" autocomplete="new-password" required :restore="false" />
            <x-forms.errors name="password_confirmation" />
        </div>

        <div class="flex flex-col gap-3 border-t border-line pt-5 sm:flex-row sm:items-center sm:justify-end">
            <a class="ui-link text-sm" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-signal.ui.button type="submit" variant="primary">
                {{ __('Register') }}
            </x-signal.ui.button>
        </div>
    </form>

</x-layouts.auth>
