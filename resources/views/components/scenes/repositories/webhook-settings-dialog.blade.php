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
        <p class="text-sm text-secondary">{{ __('Only pushes to :branch deploy. Duplicate deliveries are ignored.', ['branch' => $repository->branch]) }}</p>
        <form method="POST" action="{{ route('repositories.webhook.store', ['repository' => $repository, 'dialog' => 'repository-webhook-settings']) }}" class="space-y-4">
            @csrf
            @if ($repository->provider->provider === \App\Models\Provider::TYPE_GITLAB)
                <label>
                    <span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('GitLab signing token') }}</span>
                    <input
                        name="signing_token"
                        type="password"
                        required
                        autocomplete="off"
                        placeholder="whsec_…"
                        class="input secondary w-full rounded-lg"
                    >
                    <x-forms.errors name="signing_token" />
                </label>
            @endif
            <button
                type="submit"
                class="button button--primary"
                @if ($repository->webhook_enabled)
                    onclick="return confirm({{ Illuminate\Support\Js::from(__('Rotate the webhook secret for :repository? The current secret will stop working immediately.', ['repository' => $repository->name])) }})"
                @endif
            >
                {{ $repository->webhook_enabled ? __('Rotate webhook secret') : __('Enable webhook') }}
            </button>
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
