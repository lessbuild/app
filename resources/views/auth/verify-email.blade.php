<x-signal.layouts.auth :title="__('Verify your email')" :heading="__('Check your inbox')" :description="__('We sent a verification link to :email. Open it to finish setting up your account.', ['email' => auth()->user()?->email])">
    @if (session('status') === 'verification-link-sent')
        <x-signal.ui.alert tone="success" class="mb-5" role="status">{{ __('A new verification link is on its way.') }}</x-signal.ui.alert>
    @endif
    <div class="flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-signal.ui.button variant="primary" type="submit">{{ __('Resend link') }}</x-signal.ui.button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-signal.ui.button variant="ghost" type="submit">{{ __('Sign out') }}</x-signal.ui.button>
        </form>
    </div>
</x-signal.layouts.auth>
