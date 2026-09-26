<x-signal.layouts.auth :title="__('Confirm your password')" :eyebrow="__('Account security')" :heading="__('Confirm your password')" :description="__('This is a sensitive action. Enter your password to continue.')">
    <form method="POST" action="{{ route('password.confirm.store') }}" class="grid gap-5">
        @csrf
        <x-signal.ui.input-field name="password" :label="__('Password')" type="password" autocomplete="current-password" required autofocus :restore="false" />
        <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Confirm') }}</x-signal.ui.button>
    </form>
</x-signal.layouts.auth>
