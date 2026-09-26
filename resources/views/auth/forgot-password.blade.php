<x-signal.layouts.auth :title="__('Reset your password')" :heading="__('Reset your password')" :description="__('Enter your email and we’ll send a link to choose a new password.')">
    <form method="POST" action="{{ route('password.email') }}" class="grid gap-5">
        @csrf
        <x-signal.ui.input-field name="email" :label="__('Email address')" type="email" autocomplete="email" required autofocus />
        <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Email reset link') }}</x-signal.ui.button>
    </form>

    <x-slot:footer>
        <a href="{{ route('login') }}" class="font-bold text-primary underline">{{ __('Back to sign in') }}</a>
    </x-slot:footer>
</x-signal.layouts.auth>
