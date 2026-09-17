<x-layouts.app>
    <x-layouts.partials.heading
        icon="chip"
        :title="__('GitHub App setup')"
        :description="__('Install the downloaded GitHub App private key without using a terminal. This one-time page is available only to platform administrators on the isolated development runtime.')"
    />

    <div class="mt-6 max-w-3xl space-y-6">
        <x-ui.card class="p-5 sm:p-6">
            <h2 class="font-black text-primary">{{ __('Configuration checklist') }}</h2>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                @foreach ([
                    [__('App ID'), $appIdConfigured],
                    [__('App slug'), $slugConfigured],
                    [__('Webhook secret'), $webhookSecretConfigured],
                ] as [$label, $configured])
                    <div class="rounded-lg bg-secondary p-3">
                        <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ $label }}</dt>
                        <dd class="mt-1 font-semibold {{ $configured ? 'text-emerald-600' : 'text-amber-600' }}">{{ $configured ? __('Configured') : __('Missing') }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>

        <x-ui.card class="p-5 sm:p-6">
            <h2 class="font-black text-primary">{{ __('Upload the GitHub App key') }}</h2>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm leading-6 text-secondary">
                <li>{{ __('Open your GitHub App settings and generate a new private key.') }}</li>
                <li>{{ __('Return here and choose the downloaded .pem file. The file is validated and stored outside the public directory.') }}</li>
                <li>{{ __('After installation, start a fresh GitHub App connection from the Providers page.') }}</li>
            </ol>

            <p class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                {{ __('The private key is never displayed, flashed into the session, written to logs, or included in a response. Do not upload a GitHub token, App ID, slug, webhook secret, or fingerprint.') }}
            </p>

            <form method="POST" action="{{ route('admin.github-app.setup.store') }}" enctype="multipart/form-data" class="mt-6 space-y-4">
                @csrf
                <div>
                    <label for="private_key" class="block text-sm font-semibold text-primary">{{ __('GitHub App private key (.pem)') }}</label>
                    <input id="private_key" name="private_key" type="file" accept=".pem,text/plain,application/x-pem-file" required class="input secondary mt-2 block w-full rounded-lg p-3">
                    <p class="mt-2 text-xs text-secondary">{{ __('Maximum file size: 64 KB. GitHub App keys must be unencrypted RSA PEM files.') }}</p>
                    <x-forms.errors name="private_key" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" variant="primary">{{ __('Install private key') }}</x-ui.button>
                    <x-ui.button :href="route('providers.index')" variant="secondary">{{ __('Back to providers') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
