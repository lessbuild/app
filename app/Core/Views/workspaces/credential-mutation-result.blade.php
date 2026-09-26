<x-signal.layouts.platform :title="__('Credential updated')" :description="__('Credential change result.')" :navigation="[]" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces" :context-projects="$contextProjects">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Credential updated')" :description="__('The owning product applied this credential change.')" />

    <x-signal.ui.card class="mt-6 space-y-4 p-5">
        <p class="font-semibold">{{ $outcome->credentialName }} · {{ $outcome->credentialType }}</p>
        @if ($secret !== null)
            <p class="text-sm text-muted">{{ __('Copy this secret now. It is shown only in this response and cannot be recovered later.') }}</p>
            <x-signal.ui.code-block :code="$secret" data-secret-response />
            <p class="text-sm text-muted">{{ __('If this response is lost, the secret cannot be shown again. You may rotate the credential after returning to the inventory.') }}</p>
        @elseif ($outcome->status === 'secret_unavailable')
            <x-signal.ui.alert tone="warning">{{ __('This operation was already applied, but its one-time secret is no longer available. Rotate the credential to issue a new secret.') }}</x-signal.ui.alert>
        @else
            <p class="text-sm text-muted">{{ __('The credential was revoked. No secret was produced.') }}</p>
        @endif
        <x-signal.ui.button :href="route('core.workspace.credentials', $workspace)" variant="secondary">{{ __('Return to credential inventory') }}</x-signal.ui.button>
    </x-signal.ui.card>
</x-signal.layouts.platform>
