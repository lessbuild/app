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

    <form method="POST" action="{{ route('register') }}">
        @csrf
        @if($invitationToken ?? null)<input type="hidden" name="invite" value="{{ $invitationToken }}">@endif

        <div class="grid grid-cols-6 gap-6">

            <!--
             ! ------------------------------------------------------------
             ! Your First name
             ! ------------------------------------------------------------
             !-->
            <div class="col-span-6">
                <label>
                    <span class="text-secondary text-sm pb-1 block">
                        {{ __('Name') }}
                    </span>
                    <input
                        type="text"
                        class="input secondary rounded-sm"
                        value="{{ old('name', $invitation?->name) }}"
                        name="name"
                        placeholder="{{ __('Ex: John Doe') }}">
                </label>
                <x-forms.errors name="name"></x-forms.errors>
            </div>

            <!--
             ! ------------------------------------------------------------
             ! Your email address
             ! ------------------------------------------------------------
             !-->
            <div class="col-span-6">
                <label>
                    <span class="text-secondary text-sm pb-1 block">
                        {{ __('Email') }}
                    </span>
                    <input
                        type="text"
                        class="input secondary rounded-sm"
                        value="{{ old('email', $invitation?->email) }}"
                        name="email"
                        @readonly($invitation ?? false)
                        placeholder="{{ __('Ex: johndoe@mail.com') }}">
                </label>
                <x-forms.errors name="email"></x-forms.errors>
            </div>

            <!--
             ! ------------------------------------------------------------
             ! Your password
             ! ------------------------------------------------------------
             !-->
            <div class="col-span-6">
                <label>
                    <span class="text-secondary text-sm pb-1 block">
                        {{ __('Password') }}
                    </span>
                    <input
                        type="password"
                        class="input secondary rounded-sm"
                        name="password"
                        autocomplete="new-password"
                        placeholder="********">
                </label>
                <x-forms.errors name="password"></x-forms.errors>
            </div>

            <!--
             ! ------------------------------------------------------------
             ! Confirm your password
             ! ------------------------------------------------------------
             !-->
            <div class="col-span-6">
                <label>
                    <span class="text-secondary text-sm pb-1 block">
                        {{ __('Password Confirmation') }}
                    </span>
                    <input
                        type="password"
                        class="input secondary rounded-sm"
                        name="password_confirmation"
                        autocomplete="new-password"
                        placeholder="********">
                </label>
                <x-forms.errors name="password_confirmation"></x-forms.errors>
            </div>
        </div>


        <div class="flex items-center justify-end mt-4">
            <a
                class="underline tracking-tight text-sm text-secondary hover:text-primary"
                href="{{ route('login') }}"
            >
                {{ __('Already registered?') }}
            </a>

            <button type="submit" class="button tertiary rounded-sm ml-3">
                {{ __('Register') }}
            </button>
        </div>
    </form>

</x-layouts.auth>
