<x-signal.layouts.auth :title="__('Two-factor verification')" :eyebrow="__('Account security')" :heading="__('Verify it’s you')" :description="__('Enter the code from your authenticator app, or one of your recovery codes.')">
    <form method="POST" action="{{ route('two-factor.login.store') }}" class="grid gap-5">
        @csrf
        <x-signal.ui.input-field name="code" :label="__('Authentication code')" autocomplete="one-time-code" inputmode="numeric" autofocus :restore="false" />
        <x-signal.ui.input-field name="recovery_code" :label="__('Or a recovery code')" autocomplete="off" :restore="false" />
        <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Verify and sign in') }}</x-signal.ui.button>
    </form>
</x-signal.layouts.auth>
