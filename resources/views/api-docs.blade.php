<x-layouts.core
    :title="__('Control plane API')"
    :description="__('Automate projects, deployments, runtime state, scaling, and workflow configuration with scoped Bearer tokens.')"
    :canonical="route('api-docs')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold focus:text-ink focus:shadow-xl">
        {{ __('Skip to main content') }}
    </a>
    <x-layouts.public-header navigation-label="API navigation" />

    <main id="main-content" tabindex="-1" class="min-h-screen bg-page px-4 py-10 text-ink sm:px-6 sm:py-14">
        <div class="mx-auto max-w-5xl">
            <header class="flex flex-col gap-5 border-b border-line pb-8 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="ui-eyebrow mt-6">API v1</p>
                    <h1 class="mt-2 text-4xl font-black tracking-tight">{{ __('Control plane API') }}</h1>
                    <p class="mt-3 max-w-2xl leading-7 text-muted">{{ __('Automate projects, deployments, runtime state, scaling, and workflow configuration with scoped Bearer tokens.') }}</p>
                </div>
                <x-ui.button href="/openapi.json" variant="primary" download>{{ __('Download OpenAPI specification') }}</x-ui.button>
            </header>

            <x-ui.card class="mt-8 p-6 sm:p-7">
                <h2 class="text-xl font-black">{{ __('Authentication') }}</h2>
                <pre class="library-code mt-4"><code>Authorization: Bearer YOUR_TOKEN
Accept: application/json</code></pre>
                <p class="mt-3 text-sm leading-6 text-muted">{{ __('Create named read, deploy, or manage tokens from Automation. Tokens are shown once and can be rotated or revoked.') }}</p>
            </x-ui.card>

            @php
                $apiOperations = [
                    ['me', 'GET', '/api/v1/me', 'read', 'Current user and workspace'],
                    ['projects', 'GET', '/api/v1/projects', 'read', 'List applications and environments'],
                    ['project', 'GET', '/api/v1/projects/{project}', 'read', 'Get an application'],
                    ['workflow', 'PUT', '/api/v1/projects/{project}/workflow', 'manage', 'Apply buildpusher.yaml'],
                    ['deployments', 'GET', '/api/v1/deployments', 'read', 'List recent deployments'],
                    ['deployment', 'GET', '/api/v1/deployments/{build}', 'read', 'Get a deployment'],
                    ['deploy', 'POST', '/api/v1/environments/{environment}/deploy', 'deploy', 'Queue a deployment'],
                    ['scale', 'PATCH', '/api/v1/environments/{environment}/scale', 'manage', 'Change desired capacity'],
                    ['runtime', 'PATCH', '/api/v1/environments/{environment}/runtime', 'manage', 'Hibernate or resume'],
                ];
            @endphp

            <details id="api-contents" class="ui-card group mt-6 overflow-hidden">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 font-bold text-ink sm:px-6 [&::-webkit-details-marker]:hidden">
                    <span>{{ __('API operations') }}</span>
                    <span class="flex items-center gap-2 text-sm font-normal text-muted"><span>{{ count($apiOperations) }} {{ __('endpoints') }}</span><span class="text-lg leading-none transition group-open:rotate-45" aria-hidden="true">+</span></span>
                </summary>
                <nav class="grid gap-2 border-t border-line p-4 sm:grid-cols-2" aria-label="{{ __('API operations') }}">
                    @foreach ($apiOperations as [$anchor, $method, $path, $scope, $description])
                        <a href="#api-operation-{{ $anchor }}" class="ui-card ui-card--interactive block px-4 py-3">
                            <x-ui.badge tone="accent" class="font-mono">{{ $method }}</x-ui.badge>
                            <code class="mt-1 block break-all text-sm text-ink">{{ $path }}</code>
                        </a>
                    @endforeach
                </nav>
            </details>

            <x-ui.card class="mt-6 overflow-hidden">
                <div class="border-b border-line p-5 sm:p-6">
                    <p class="ui-eyebrow">{{ __('Available operations') }}</p>
                    <h2 class="mt-1 text-xl font-black">{{ __('Endpoints') }}</h2>
                </div>
                @foreach ($apiOperations as [$anchor, $method, $path, $scope, $description])
                    <article id="api-operation-{{ $anchor }}" class="scroll-mt-6 grid gap-3 border-b border-line p-5 last:border-0 sm:grid-cols-[5rem_1fr_8rem] sm:items-center">
                        <x-ui.badge tone="neutral" class="w-fit font-mono">{{ $method }}</x-ui.badge>
                        <div>
                            <code id="api-path-{{ $anchor }}" class="break-all text-sm text-ink">{{ $path }}</code>
                            <p class="mt-1 text-xs text-muted">{{ __($description) }}</p>
                        </div>
                        <x-ui.badge tone="accent" class="w-fit sm:justify-self-end">{{ $scope }}</x-ui.badge>
                    </article>
                @endforeach
            </x-ui.card>

            <x-ui.card class="mt-6 p-6 sm:p-7">
                <h2 class="text-xl font-black">{{ __('Request example') }}</h2>
                <pre class="library-code mt-4"><code>curl -X POST \
  -H "Authorization: Bearer $BUILDPUSHER_TOKEN" \
  -H "Accept: application/json" \
  {{ url('/api/v1/environments/1/deploy') }}</code></pre>
            </x-ui.card>
        </div>
    </main>
</x-layouts.core>
