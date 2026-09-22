<x-layouts.auth>
    <x-slot name="title">
        {{ __('Verify your email') }}
    </x-slot>

    <x-slot name="description">
        {{ __('We sent a verification link to :email. Open it before managing infrastructure or deployments.', ['email' => auth()->user()->email]) }}
    </x-slot>

    @if (session('status') === 'verification-link-sent')
        <x-ui.alert tone="success" role="status">
            {{ __('A new verification link has been sent.') }}
        </x-ui.alert>
    @endif

    <div class="mt-6 flex flex-col gap-3 border-t border-line pt-5 sm:flex-row sm:items-center sm:justify-end">
        <a href="{{ route('account.index') }}" class="ui-link text-sm">
            {{ __('Correct my email') }}
        </a>
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button type="submit" variant="primary">{{ __('Resend email') }}</x-ui.button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-ui.button type="submit" variant="secondary">{{ __('Logout') }}</x-ui.button>
        </form>
    </div>
</x-layouts.auth>
