@props([
    'repository',
    'open' => false,
])

<x-dialogs.modal
    id="repository-webhook-settings-dialog"
    :title="__('Webhook settings')"
    :description="__('Enable, rotate or disable authenticated push deployments for this repository.')"
    :open="$open"
>
    <div class="space-y-4">
        <p class="text-sm text-muted">{{ __('Only pushes to :branch deploy. Duplicate deliveries are ignored.', ['branch' => $repository->branch]) }}</p>
        <form method="POST" action="{{ route('repositories.webhook.store', ['repository' => $repository, 'dialog' => 'repository-webhook-settings']) }}" class="space-y-4">
            @csrf
            @if ($repository->provider->provider === \App\Modules\Deployer\Models\Provider::TYPE_GITLAB)
                <label>
                    <span class="ui-label">{{ __('GitLab signing token') }}</span>
                    <input
                        name="signing_token"
                        type="password"
                        required
                        autocomplete="off"
                        placeholder="whsec_…"
                        class="ui-input mt-2 w-full"
                    >
                    <x-forms.errors name="signing_token" />
                </label>
            @endif
            @if ($repository->webhook_enabled)
                <x-ui.button
                    type="submit"
                    variant="primary"
                    class="ui-btn-sm"
                    onclick="return confirm({{ Illuminate\Support\Js::from(__('Rotate the webhook secret for :repository? The current secret will stop working immediately.', ['repository' => $repository->name])) }})"
                >{{ __('Rotate webhook secret') }}</x-ui.button>
            @else
                <x-ui.button type="submit" variant="primary" class="ui-btn-sm">{{ __('Enable webhook') }}</x-ui.button>
            @endif
        </form>

        @if ($repository->webhook_enabled)
            <form
                method="POST"
                action="{{ route('repositories.webhook.destroy', ['repository' => $repository, 'dialog' => 'repository-webhook-settings']) }}"
                onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Disable webhook deployments for :repository?', ['repository' => $repository->name])) }})"
            >
                @csrf
                @method('DELETE')
                <x-ui.button type="submit" variant="danger">{{ __('Disable webhook') }}</x-ui.button>
            </form>
        @endif
    </div>
</x-dialogs.modal>
