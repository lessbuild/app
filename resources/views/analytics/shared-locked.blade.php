{{-- The password prompt of a shared report. --}}
<x-signal.layouts.base :title="__(':site analytics', ['site' => $site->name])">
    <main id="main-content" tabindex="-1" class="mx-auto grid min-h-screen max-w-md content-center gap-6 px-4 py-10">
        <x-signal.ui.card class="grid gap-5 p-6">
            <div>
                <p class="ui-eyebrow">{{ __('Shared report') }}</p>
                <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ $site->name }}</h1>
                <p class="mt-2 text-sm text-muted">{{ __('This report is protected. Enter the password you were given.') }}</p>
            </div>
            <form method="POST" action="{{ route('analytics.shared.unlock', $token) }}" class="grid gap-4">
                @csrf
                <x-signal.ui.input-field name="password" type="password" :label="__('Password')" autocomplete="current-password" required autofocus />
                <x-signal.ui.button type="submit" variant="primary" class="w-full justify-center">{{ __('View report') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.card>
    </main>
</x-signal.layouts.base>
