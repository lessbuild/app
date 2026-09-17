<x-layouts.core
    :title="__('Control plane API')"
    :description="__('Automate projects, deployments, runtime state, scaling, and workflow configuration with scoped Bearer tokens.')"
    :canonical="route('api-docs')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-primary focus:px-4 focus:py-3 focus:font-semibold focus:text-primary focus:shadow-xl">
        {{ __('Skip to main content') }}
    </a>

    <main id="main-content" tabindex="-1" class="min-h-screen bg-secondary px-4 py-10 text-primary sm:px-6 sm:py-14">
        <div class="mx-auto max-w-5xl">
            <header class="flex flex-col gap-5 border-b border-primary pb-8 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <a href="{{ url('/') }}" class="inline-flex min-h-[2.5rem] items-center text-sm font-bold text-ternary">← {{ config('app.name') }}</a>
                    <p class="mt-6 text-xs font-bold uppercase tracking-widest text-ternary">API v1</p>
                    <h1 class="mt-2 text-4xl font-black tracking-tight">{{ __('Control plane API') }}</h1>
                    <p class="mt-3 max-w-2xl leading-7 text-secondary">{{ __('Automate projects, deployments, runtime state, scaling, and workflow configuration with scoped Bearer tokens.') }}</p>
                </div>
                <x-ui.button href="/openapi.json" variant="primary" download>{{ __('Download OpenAPI specification') }}</x-ui.button>
            </header>

            <x-ui.card class="mt-8 p-6 sm:p-7">
                <h2 class="text-xl font-black">{{ __('Authentication') }}</h2>
                <pre class="mt-4 overflow-auto rounded-xl bg-slate-950 p-4 text-sm text-slate-100"><code>Authorization: Bearer YOUR_TOKEN
Accept: application/json</code></pre>
                <p class="mt-3 text-sm leading-6 text-secondary">{{ __('Create named read, deploy, or manage tokens from Automation. Tokens are shown once and can be rotated or revoked.') }}</p>
            </x-ui.card>

            <x-ui.card class="mt-6 overflow-hidden">
                <div class="border-b border-primary p-5 sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Available operations') }}</p>
                    <h2 class="mt-1 text-xl font-black">{{ __('Endpoints') }}</h2>
                </div>
                @foreach ([
                    ['GET', '/api/v1/me', 'read', 'Current user and workspace'],
                    ['GET', '/api/v1/projects', 'read', 'List applications and environments'],
                    ['GET', '/api/v1/projects/{project}', 'read', 'Get an application'],
                    ['PUT', '/api/v1/projects/{project}/workflow', 'manage', 'Apply buildpusher.yaml'],
                    ['GET', '/api/v1/deployments', 'read', 'List recent deployments'],
                    ['GET', '/api/v1/deployments/{build}', 'read', 'Get a deployment'],
                    ['POST', '/api/v1/environments/{environment}/deploy', 'deploy', 'Queue a deployment'],
                    ['PATCH', '/api/v1/environments/{environment}/scale', 'manage', 'Change desired capacity'],
                    ['PATCH', '/api/v1/environments/{environment}/runtime', 'manage', 'Hibernate or resume'],
                ] as [$method, $path, $scope, $description])
                    <article class="grid gap-3 border-b border-primary p-5 last:border-0 sm:grid-cols-[5rem_1fr_8rem] sm:items-center">
                        <x-ui.badge tone="neutral" class="w-fit font-mono">{{ $method }}</x-ui.badge>
                        <div>
                            <code class="break-all text-sm text-primary">{{ $path }}</code>
                            <p class="mt-1 text-xs text-secondary">{{ __($description) }}</p>
                        </div>
                        <x-ui.badge tone="accent" class="w-fit sm:justify-self-end">{{ $scope }}</x-ui.badge>
                    </article>
                @endforeach
            </x-ui.card>

            <x-ui.card class="mt-6 p-6 sm:p-7">
                <h2 class="text-xl font-black">{{ __('Request example') }}</h2>
                <pre class="mt-4 overflow-auto rounded-xl bg-slate-950 p-4 text-sm text-slate-100"><code>curl -X POST \
  -H "Authorization: Bearer $BUILDPUSHER_TOKEN" \
  -H "Accept: application/json" \
  {{ url('/api/v1/environments/1/deploy') }}</code></pre>
            </x-ui.card>
        </div>
    </main>
</x-layouts.core>
