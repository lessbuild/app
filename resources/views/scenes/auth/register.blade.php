<x-layouts.auth>

    <x-slot name="title">
        {{ __('Sign up for an account') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Sign up for an account to easily manage your work life.') }}

        <div class="mt-10 flex space-x-4">
            <button class="button tertiary w-full">
                <svg class="w-6 h-6 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#github"></use>
                </svg>
                <span>Github</span>
            </button>
            <button class="button tertiary w-full">
                <svg class="w-6 h-6 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#github"></use>
                </svg>
                <span>Github</span>
            </button>
        </div>

        <div class="my-7 flex items-center space-x-3">
            <div class="h-px flex-1 bg-secondary border border-secondary"></div>
            <p class="text-xs text-primary uppercase">or sign up with email</p>
            <div class="h-px flex-1 bg-secondary border border-secondary"></div>
        </div>
    </x-slot>

    <form method="POST" action="{{ route('register') }}">
        @csrf

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
                        class="input secondary rounded"
                        value="{{ old('name') }}"
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
                        class="input secondary rounded"
                        value="{{ old('email') }}"
                        name="email"
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
                        class="input secondary rounded"
                        value="{{ old('password') }}"
                        name="password"
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
                        class="input secondary rounded"
                        value="{{ old('password_confirmation') }}"
                        name="password_confirmation"
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

            <button class="button tertiary rounded ml-3">
                {{ __('Register') }}
            </button>
        </div>
    </form>

</x-layouts.auth>
