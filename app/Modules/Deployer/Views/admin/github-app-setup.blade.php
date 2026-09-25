<x-layouts.app>
    <x-signal.ui.page-header
        icon="chip"
        :title="__('GitHub App setup')"
        :description="__('Install the downloaded GitHub App private key without using a terminal. This one-time page is available only to platform administrators on the isolated development runtime.')"
    />

    <div class="mt-6 max-w-3xl space-y-6">
        <x-signal.ui.card class="p-5 sm:p-6">
            <h2 class="font-extrabold text-ink">{{ __('Configuration checklist') }}</h2>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                @foreach ([
                    [__('App ID'), $appIdConfigured],
                    [__('App slug'), $slugConfigured],
                    [__('Webhook secret'), $webhookSecretConfigured],
                ] as [$label, $configured])
                    <x-signal.ui.panel class="ui-panel bg-surface-muted p-3">
                        <dt class="text-xs font-bold uppercase tracking-wide text-muted">{{ $label }}</dt>
                        <dd class="mt-1 font-semibold {{ $configured ? 'text-success' : 'text-warning' }}">{{ $configured ? __('Configured') : __('Missing') }}</dd>
                    </x-signal.ui.panel>
                @endforeach
            </dl>
        </x-signal.ui.card>

        <x-signal.ui.card class="p-5 sm:p-6">
            <h2 class="font-extrabold text-ink">{{ __('Upload the GitHub App key') }}</h2>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm leading-6 text-muted">
                <li>{{ __('Open your GitHub App settings and generate a new private key.') }}</li>
                <li>{{ __('Return here and choose the downloaded .pem file. The file is validated and stored outside the public directory.') }}</li>
                <li>{{ __('After installation, start a fresh GitHub App connection from the Providers page.') }}</li>
            </ol>

            <x-signal.ui.alert as="p" tone="warning" class="mt-4 text-sm leading-6">
                {{ __('The private key is never displayed, flashed into the session, written to logs, or included in a response. Do not upload a GitHub token, App ID, slug, webhook secret, or fingerprint.') }}
            </x-signal.ui.alert>

            <form method="POST" action="{{ route('admin.github-app.setup.store') }}" enctype="multipart/form-data" class="mt-6 space-y-4">
                @csrf
                <div>
                    <label for="private_key" class="ui-label">{{ __('GitHub App private key (.pem)') }}</label>
                    <x-signal.ui.input id="private_key" name="private_key" type="file" accept=".pem,text/plain,application/x-pem-file" required class="ui-input mt-2 block w-full p-3" :restore="false" />
                    <p class="ui-help">{{ __('Maximum file size: 64 KB. GitHub App keys must be unencrypted RSA PEM files.') }}</p>
                    <x-forms.errors name="private_key" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Install private key') }}</x-signal.ui.button>
                    <x-signal.ui.button :href="route('providers.index')" variant="secondary">{{ __('Back to providers') }}</x-signal.ui.button>
                </div>
            </form>
        </x-signal.ui.card>
    </div>
</x-layouts.app>
