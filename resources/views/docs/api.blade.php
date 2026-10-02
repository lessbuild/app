<x-signal.layouts.base :title="__('API reference')" :description="__('The platform’s public API: Deployer API v1, Monitoring ingest, heartbeats and queues, and the analytics tracker.')" indexable :canonical="route('docs.api')" :structured-data="[\App\Support\StructuredData::breadcrumbs([config('app.name') => route('home'), __('API reference') => route('docs.api')])]">
    <main id="main-content" tabindex="-1" class="mx-auto grid max-w-5xl gap-6 px-4 py-10 sm:px-8 sm:py-14">
        <header>
            <p class="ui-eyebrow">{{ config('app.name') }}</p>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('API reference') }}</h1>
            <p class="mt-3 max-w-3xl whitespace-pre-line text-sm leading-6 text-muted">{{ $description }}</p>
            <p class="mt-3 text-sm"><a href="{{ route('docs.openapi') }}" class="font-bold text-primary underline">{{ __('OpenAPI description (JSON)') }}</a> · {{ __('Create tokens under Account → API tokens.') }}</p>
        </header>
        @foreach ($groups as $tag => $operations)
            <x-signal.ui.table :caption="$tag">
                <x-slot:head><tr><th scope="col">{{ __('Request') }}</th><th scope="col">{{ __('What it does') }}</th><th scope="col">{{ __('Scope') }}</th></tr></x-slot:head>
                @foreach ($operations as $operation)
                    <tr>
                        <td class="whitespace-nowrap font-mono text-xs"><span class="font-bold">{{ $operation['method'] }}</span> {{ $operation['path'] }}</td>
                        <td>{{ $operation['summary'] }}</td>
                        <td class="font-mono text-xs">{{ implode(', ', $operation['scopes']) ?: '—' }}</td>
                    </tr>
                @endforeach
            </x-signal.ui.table>
        @endforeach
    </main>
</x-signal.layouts.base>
