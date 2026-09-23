<x-signal.layouts.core :title="__('Workspace invitation')" :description="__('Review your Buildpusher workspace invitation.')" livewire="false">
    <main class="mx-auto grid min-h-screen w-full max-w-screen-sm place-items-center px-4 py-10 sm:px-6">
        <x-signal.ui.card class="w-full p-6 sm:p-8">
            <p class="ui-eyebrow">{{ __('Workspace invitation') }}</p>
            <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ __('Join :workspace', ['workspace' => $invitation->workspace->name]) }}</h1>
            <p class="mt-3 text-sm leading-6 text-muted">
                {{ __(':name invited :email to join as :role.', [
                    'name' => $invitation->invitedBy?->name ?: config('app.name'),
                    'email' => $invitation->email,
                    'role' => str($invitation->role)->headline(),
                ]) }}
            </p>
            <p class="mt-2 text-xs leading-5 text-muted">{{ __('Workspace membership does not automatically grant access to Deployer, Monitor, or Analytics, and does not start a subscription.') }}</p>

            @if ($user && $emailMatches)
                <form method="POST" action="{{ route('platform.workspace-invitations.accept', ['token' => $token]) }}" class="mt-6">
                    @csrf
                    <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Accept invitation') }}</x-signal.ui.button>
                </form>
            @elseif ($user)
                <x-signal.ui.alert tone="warning" class="mt-6">
                    {{ __('This invitation was sent to a different email address. Sign out, then sign in with :email.', ['email' => $invitation->email]) }}
                </x-signal.ui.alert>
                <form method="POST" action="{{ route('platform.logout') }}" class="mt-4">
                    @csrf
                    <x-signal.ui.button variant="secondary" type="submit" class="w-full justify-center">{{ __('Sign out') }}</x-signal.ui.button>
                </form>
            @else
                <div class="mt-6 grid gap-3">
                    <x-signal.ui.button variant="primary" :href="$loginUrl" class="w-full justify-center">{{ __('Sign in to accept') }}</x-signal.ui.button>
                    <x-signal.ui.button variant="secondary" :href="route('platform.register', ['invitation' => $token])" class="w-full justify-center">{{ __('Create an account and join') }}</x-signal.ui.button>
                </div>
            @endif
        </x-signal.ui.card>
    </main>
</x-signal.layouts.core>
