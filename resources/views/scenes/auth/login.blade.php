<x-layouts.auth>

    <x-slot name="title">
        {{ __('Sign in to your account') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Sign in to your manage your websites and servers.') }}

        <div class="mt-10 flex space-x-4">
            <a href="{{ route('social.login', 'github') }}" class="button tertiary w-full">
                <svg class="w-6 h-6 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#github"></use>
                </svg>
                <span>Github</span>
            </a>
            <a href="{{ route('social.login', 'gitlab') }}" class="button tertiary w-full">
                <svg class="w-6 h-6 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#gitlab"></use>
                </svg>
                <span>Gitlab</span>
            </a>
            <a href="{{ route('social.login', 'bitbucket') }}" class="button tertiary w-full">
                <svg class="w-6 h-6 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#bitbucket"></use>
                </svg>
                <span>Bitbucket</span>
            </a>
        </div>

        <div class="my-7 flex items-center space-x-3">
            <div class="h-px flex-1 bg-secondary border border-secondary"></div>
            <p class="text-xs text-primary uppercase">or sign in with email</p>
            <div class="h-px flex-1 bg-secondary border border-secondary"></div>
        </div>
    </x-slot>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="grid grid-cols-6 gap-6">

            <!--
             ! ------------------------------------------------------------
             ! Email Address
             ! ------------------------------------------------------------
             !-->
            <div class="col-span-6">
                <label>
                    <span class="text-secondary text-sm">
                        {{ __('Email') }}
                    </span>
                    <input
                        id="email"
                        class="input primary rounded"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        placeholder="{{ __('Example: johndoe@mail.com') }}"
                        required autofocus/>
                </label>
            </div>

            <!--
             ! ------------------------------------------------------------
             ! Password
             ! ------------------------------------------------------------
             !-->
            <div class="col-span-6">
                <label>
                    <span class="text-secondary text-sm">
                        {{ __('Password') }}
                    </span>
                    <input
                        id="password"
                        class="input primary rounded"
                        type="password"
                        name="password"
                        placeholder="**********"
                        required
                        autocomplete="current-password" />
                </label>
            </div>
        </div>

        <!--
         ! ------------------------------------------------------------
         ! Remember Me
         ! ------------------------------------------------------------
         !-->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox"
                    class="bg-primary rounded border-primary text-secondary shadow-sm"
                    name="remember">
                <span class="ml-2 text-sm text-primary">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center gap-2 lg:justify-end mt-4">
            <a
                href="{{ route('register') }}"
                class="mr-4 underline tracking-tight text-sm text-secondary hover:text-primary"
            >
                {{ __('Need an account?') }}
            </a>
            @if (Route::has('password.request'))
                <a
                    class="underline tracking-tight text-sm text-secondary hover:text-primary"
                    href="{{ route('password.request') }}"
                >
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <button class="button tertiary rounded ml-3">
                {{ __('Login') }}
            </button>
        </div>
    </form>

</x-user::layouts.auth>
