<x-signal.layouts.account :account="$account" :title="__('Providers')" :description="__('Credentials for the clouds that host your servers, Cloudflare for DNS, and the Git hosts Deploy builds from.')">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    @if ($providers->isEmpty())
        <x-signal.ui.empty-state icon="server" :title="__('No providers yet')" :description="__('Connect DigitalOcean, Hetzner Cloud or Vultr to create servers.')">
            <x-slot:action><x-signal.ui.button :href="route('account.providers', ['dialog' => 'add-provider'])" variant="primary" data-modal-trigger="add-provider">{{ __('Connect a provider') }}</x-signal.ui.button></x-slot:action>
        </x-signal.ui.empty-state>
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Providers') }}">
                @foreach ($providers as $provider)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <a href="{{ route('account.providers.show', $provider->id) }}" class="font-extrabold text-ink hover:underline">{{ $provider->name }}</a>
                            <p class="mt-0.5 text-xs text-muted">{{ $provider->type->label() }} · {{ $provider->type->purpose() }}@if ($provider->type->hostsServers()) · {{ trans_choice(':count server|:count servers', $provider->servers_count ?? 0, ['count' => $provider->servers_count ?? 0]) }}@endif</p>
                        </div>
                        @include('account._provider-health', ['provider' => $provider])
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif

    @if ($githubApp)
        <x-signal.ui.settings-section :title="__('GitHub App')" :description="__('Install our GitHub App on your organisation or account to deploy its repositories without a personal token. Pushes deploy automatically.')">
            <div class="p-4 sm:p-6"><x-signal.ui.button :href="route('github-app.connect')" variant="secondary">{{ __('Install the GitHub App') }}</x-signal.ui.button></div>
        </x-signal.ui.settings-section>
    @endif

    <x-slot:actions>
        <x-signal.ui.button :href="route('account.inventory', 'providers')" variant="quiet" size="sm">{{ __('Export CSV') }}</x-signal.ui.button>
        <x-signal.ui.button :href="route('account.providers', ['dialog' => 'add-provider'])" variant="primary" data-modal-trigger="add-provider">{{ __('Connect a provider') }}</x-signal.ui.button>
    </x-slot:actions>
    <x-signal.overlays.form-modal id="add-provider" :title="__('Connect a provider')" :description="__('Use an API token scoped to what we need. We check it straight away and then on a schedule.')" :action="route('account.providers.store')" :submit="__('Connect provider')" form-class="grid items-start gap-5 sm:grid-cols-2">
        @include('account._provider-fields', ['provider' => null])
    </x-signal.overlays.form-modal>
</x-signal.layouts.account>
