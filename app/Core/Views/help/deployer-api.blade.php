<x-signal.layouts.core
    :title="__('Deployer API reference') . ' · Buildpusher'"
    :description="__('Automate projects, deployments, runtime state, scaling, and workflow configuration with scoped Deployer API tokens.')"
    :canonical="route('core.help.deployer.api')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation active-product="deployer" />

    <main id="main-content" tabindex="-1" class="mx-auto max-w-screen-2xl px-5 py-10 text-ink sm:px-8 sm:py-14">
        <div class="mx-auto max-w-5xl">
            <div class="flex flex-wrap justify-end gap-2">
                <x-signal.ui.button :href="route('core.help.deployer')" variant="secondary">{{ __('Deployer guide') }}</x-signal.ui.button>
                @if ($openApiUrl)<x-signal.ui.button :href="$openApiUrl" variant="primary" download>{{ __('Download OpenAPI specification') }}</x-signal.ui.button>@endif
                @if ($automationUrl)<x-signal.ui.button :href="$automationUrl" variant="secondary">{{ __('Manage Deployer tokens') }}</x-signal.ui.button>@endif
            </div>
            <header class="mt-8 border-b border-line pb-8">
                <p class="ui-eyebrow">{{ __('Deployer · API :version', ['version' => $apiVersion]) }}</p>
                <h1 class="mt-2 text-4xl font-extrabold tracking-tight">{{ __('Control plane API') }}</h1>
                <p class="mt-3 max-w-2xl leading-7 text-muted">{{ __('Automate projects, deployments, runtime state, scaling, and workflow configuration with scoped Bearer tokens.') }}</p>
                <p class="mt-2 text-sm text-muted">{{ __('API base URL: :url', ['url' => $apiBaseUrl]) }}</p>
            </header>

            <x-signal.ui.card class="mt-8 p-6 sm:p-7">
                <h2 class="text-xl font-extrabold">{{ __('Authentication') }}</h2>
                <pre class="library-code mt-4 overflow-x-auto"><code>Authorization: Bearer YOUR_TOKEN
Accept: application/json</code></pre>
                <p class="mt-3 text-sm leading-6 text-muted">{{ __('Create named read, deploy, or manage tokens from Automation. New tokens are bound to the active workspace; select projects to narrow access further. Every request combines token abilities with current workspace membership, project policy, and plan entitlements.') }}</p>
                <p class="mt-3 text-sm leading-6 text-muted">{{ __('Existing tokens keep their current behavior until revoked or rotated. Rotating an existing unscoped token binds its replacement to the active workspace.') }}</p>
            </x-signal.ui.card>

            <x-signal.ui.card class="mt-6 overflow-hidden">
                <div class="border-b border-line p-5 sm:p-6">
                    <p class="ui-eyebrow">{{ __('Available operations') }}</p>
                    <h2 class="mt-1 text-xl font-extrabold">{{ __('Endpoints') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('OpenAPI :version', ['version' => $openApiVersion]) }}</p>
                </div>
                @foreach ($apiOperations as $operation)
                    <article class="grid gap-3 border-b border-line p-5 last:border-0 sm:grid-cols-[5rem_1fr_8rem] sm:items-center">
                        <x-signal.ui.badge tone="neutral" class="w-fit font-mono">{{ $operation['method'] }}</x-signal.ui.badge>
                        <div><code class="break-all text-sm text-ink">{{ $operation['path'] }}</code><p class="mt-1 text-xs text-muted">{{ $operation['description'] }}</p></div>
                        <x-signal.ui.badge tone="accent" class="w-fit sm:justify-self-end">{{ $operation['scope'] }}</x-signal.ui.badge>
                    </article>
                @endforeach
            </x-signal.ui.card>

            <x-signal.ui.card class="mt-6 p-6 sm:p-7">
                <h2 class="text-xl font-extrabold">{{ __('Request example') }}</h2>
                <pre class="library-code mt-4 overflow-x-auto"><code>curl -X POST \
  -H "Authorization: Bearer $BUILDPUSHER_TOKEN" \
  -H "Accept: application/json" \
  {{ $apiBaseUrl }}/environments/1/deploy</code></pre>
                <p class="mt-3 text-sm leading-6 text-muted">{{ __('Use a project, deployment, and environment identifier from the same authorized Deployer workspace. Every write checks token scope, membership, product limits, and the target resource policy.') }}</p>
            </x-signal.ui.card>
        </div>
    </main>
</x-signal.layouts.core>
