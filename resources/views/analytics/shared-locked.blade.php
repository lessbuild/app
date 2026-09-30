{{-- The password prompt of a shared report. --}}
<x-signal.layouts.base :title="__(':site analytics', ['site' => $site->name])">
    <main id="main-content" tabindex="-1" class="mx-auto grid min-h-screen max-w-md content-center gap-6 px-4 py-10">
        <x-signal.ui.card class="grid gap-5 p-6">
            <div>
                <p class="ui-eyebrow">{{ __('Shared report') }}</p>
                <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ $site->name }}</h1>
                <p class="mt-2 text-sm text-muted">{{ ($embed ?? false) ? __('This report is password-protected, so it can’t be embedded. Share it without a password to embed it.') : __('This report is protected. Enter the password you were given.') }}</p>
            </div>
            @unless ($embed ?? false)
            <form method="POST" action="{{ route('analytics.shared.unlock', $token) }}" class="grid gap-4">
                @csrf
                <x-signal.ui.input-field name="password" type="password" :label="__('Password')" autocomplete="current-password" required autofocus />
                <x-signal.ui.button type="submit" variant="primary" class="w-full justify-center">{{ __('View report') }}</x-signal.ui.button>
            </form>
            @endunless
        </x-signal.ui.card>
    </main>
</x-signal.layouts.base>
