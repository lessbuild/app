<x-signal.layouts.auth :title="__('Choose a new password')" :heading="__('Choose a new password')">
    <form method="POST" action="{{ route('password.update') }}" class="grid gap-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-signal.ui.input-field name="email" :label="__('Email address')" type="email" autocomplete="username" :value="$request->string('email')->toString()" required />
        <x-signal.ui.input-field name="password" :label="__('New password')" type="password" autocomplete="new-password" required autofocus :restore="false" />
        <x-signal.ui.input-field name="password_confirmation" :label="__('Confirm new password')" type="password" autocomplete="new-password" required :restore="false" />
        <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Save password') }}</x-signal.ui.button>
    </form>
</x-signal.layouts.auth>
