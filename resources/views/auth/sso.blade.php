<x-signal.layouts.auth :title="__('Single sign-on')" :heading="__('Sign in with single sign-on')" :description="__('Enter your work email and we’ll send you to your company’s sign-in page.')">
    <form method="POST" action="{{ route('sso.login.store') }}" class="grid gap-5">
        @csrf
        <x-signal.ui.input-field name="email" :label="__('Work email address')" type="email" autocomplete="username" required autofocus />
        <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Continue') }}</x-signal.ui.button>
    </form>
    <x-slot:footer>
        <a href="{{ route('login') }}" class="font-bold text-primary underline">{{ __('Sign in with a password instead') }}</a>
    </x-slot:footer>
</x-signal.layouts.auth>
