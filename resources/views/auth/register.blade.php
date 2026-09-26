<x-signal.layouts.auth :title="__('Create an account')" :heading="__('Create your account')" :description="__('Start free. Turn on the services you need for each project and pay only for what you use.')">
    <form method="POST" action="{{ route('register.store') }}" class="grid gap-5">
        @csrf
        <x-signal.ui.input-field name="name" :label="__('Your name')" autocomplete="name" required autofocus />
        <x-signal.ui.input-field name="email" :label="__('Work email')" type="email" autocomplete="email" required />
        <x-signal.ui.input-field name="password" :label="__('Password')" type="password" autocomplete="new-password" required :restore="false" />
        <x-signal.ui.input-field name="password_confirmation" :label="__('Confirm password')" type="password" autocomplete="new-password" required :restore="false" />
        <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Create account') }}</x-signal.ui.button>
    </form>
    <div class="mt-5">@include('auth.partials.social-sign-in')</div>

    <x-slot:footer>
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" class="font-bold text-primary underline">{{ __('Sign in') }}</a>
    </x-slot:footer>
</x-signal.layouts.auth>
