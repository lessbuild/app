@props([
    'title',
    'heading',
    'description' => null,
    'eyebrow' => null,
])

<x-signal.layouts.base :title="$title" :description="$description">
    <main id="main-content" class="mx-auto grid min-h-screen w-full max-w-md place-items-center px-4 py-10 sm:px-6">
        <div class="w-full">
            <a href="{{ url('/') }}" class="mb-6 inline-flex items-center gap-3 rounded-control text-lg font-extrabold tracking-tight text-ink focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-focus">
                <span class="grid h-10 w-10 place-items-center rounded-card bg-ink text-surface" aria-hidden="true">↗</span>
                <span>{{ config('app.name') }}</span>
            </a>

            <x-signal.ui.card class="p-6 sm:p-8">
                @if ($eyebrow)
                    <p class="ui-eyebrow">{{ $eyebrow }}</p>
                @endif
                <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-ink">{{ $heading }}</h1>
                @if ($description)
                    <p class="mt-2 text-sm leading-6 text-muted">{{ $description }}</p>
                @endif

                @if (session('status'))
                    <x-signal.ui.alert tone="success" class="mt-5" role="status">{{ session('status') }}</x-signal.ui.alert>
                @endif

                <div class="mt-6">{{ $slot }}</div>
            </x-signal.ui.card>

            @isset($footer)
                <div class="mt-5 text-center text-sm text-muted">{{ $footer }}</div>
            @endisset
        </div>
    </main>
</x-signal.layouts.base>
