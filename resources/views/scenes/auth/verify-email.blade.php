<x-layouts.auth>
    <x-slot name="title">
        {{ __('Verify your email') }}
    </x-slot>

    <x-slot name="description">
        {{ __('We sent a verification link to :email. Open it before managing infrastructure or deployments.', ['email' => auth()->user()->email]) }}
    </x-slot>

    @if (session('status') === 'verification-link-sent')
        <div class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-700">
            {{ __('A new verification link has been sent.') }}
        </div>
    @endif

    <div class="mt-6 flex flex-wrap items-center justify-end gap-3">
        <a href="{{ route('account.index') }}" class="text-sm text-secondary underline hover:text-primary">
            {{ __('Correct my email') }}
        </a>
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="button tertiary">{{ __('Resend email') }}</button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="button tertiary">{{ __('Logout') }}</button>
        </form>
    </div>
</x-layouts.auth>
