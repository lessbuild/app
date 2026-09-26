@if ($invitation === null)
    <x-signal.layouts.auth :title="__('Invitation unavailable')" :heading="__('This invitation isn’t available')" :description="__('It may have expired, been used, or been withdrawn. Ask the person who invited you for a new link.')" />
@else
    <x-signal.layouts.auth :title="__('Join :account', ['account' => $invitation->account->name])" :eyebrow="__('Invitation')" :heading="__('Join :account', ['account' => $invitation->account->name])" :description="__('You were invited as :role. :description', ['role' => $invitation->role->label(), 'description' => $invitation->role->description()])">
        @if ($user === null)
            <p class="text-sm text-muted">{{ __('Sign in or create an account with :email to accept.', ['email' => $invitation->email]) }}</p>
            <div class="mt-5 flex flex-wrap gap-3">
                <x-signal.ui.button variant="primary" :href="route('login')">{{ __('Sign in') }}</x-signal.ui.button>
                <x-signal.ui.button variant="secondary" :href="route('register')">{{ __('Create an account') }}</x-signal.ui.button>
            </div>
        @else
            @error('invitation')
                <x-signal.ui.alert tone="danger" class="mb-5" role="alert">{{ $message }}</x-signal.ui.alert>
            @enderror
            <form method="POST" action="{{ route('invitations.accept', $token) }}">
                @csrf
                <x-signal.ui.button variant="primary" type="submit" class="w-full justify-center">{{ __('Accept and join') }}</x-signal.ui.button>
            </form>
        @endif
    </x-signal.layouts.auth>
@endif
