<x-signal.layouts.account :account="$account" :title="__('Providers')" :description="__('Credentials for the clouds that host your servers, Cloudflare for DNS, and the Git hosts Deploy builds from.')">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    @if ($providers->isEmpty())
        <x-signal.ui.empty-state icon="server" :title="__('No providers yet')" :description="__('Connect DigitalOcean, Hetzner Cloud or Vultr to create servers.')" />
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

    <x-signal.ui.settings-section :title="__('Connect a provider')" :description="__('Use an API token scoped to what we need. We check it straight away and then on a schedule.')">
        <form method="POST" action="{{ route('account.providers.store') }}" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
            @csrf
            @include('account._provider-fields', ['provider' => null])
            <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Connect provider') }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.settings-section>
</x-signal.layouts.account>
