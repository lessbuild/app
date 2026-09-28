<x-signal.layouts.public :title="config('app.name')" :description="__(config('marketing.summary'))" :canonical="route('home')">
    <section class="grid gap-5 text-center">
        <h1 class="mx-auto max-w-3xl text-4xl font-extrabold tracking-tight text-ink sm:text-5xl">{{ __(config('marketing.headline')) }}</h1>
        <p class="mx-auto max-w-2xl text-lg text-muted">{{ __(config('marketing.summary')) }}</p>
        <div class="flex flex-wrap justify-center gap-3">
            <x-signal.ui.button :href="route('register')" variant="primary">{{ __('Start free') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('pricing')" variant="secondary">{{ __('See pricing') }}</x-signal.ui.button>
        </div>
    </section>

    <section aria-labelledby="services-heading" class="grid gap-4">
        <h2 id="services-heading" class="text-2xl font-extrabold text-ink">{{ __('Four services, one platform') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($services as $service)
                <x-signal.ui.card class="grid gap-3 p-6">
                    <p class="ui-eyebrow">{{ __(config('marketing.services.'.$service->key().'.eyebrow')) }}</p>
                    <h3 class="text-xl font-extrabold text-ink"><a href="{{ route('features', $service->key()) }}" class="hover:underline">{{ $service->name() }}</a></h3>
                    <p class="text-sm text-muted">{{ $service->tagline() }}</p>
                    <a href="{{ route('features', $service->key()) }}" class="text-sm font-bold text-primary">{{ __('What :service does', ['service' => $service->name()]) }} →</a>
                </x-signal.ui.card>
            @endforeach
        </div>
    </section>

    <section aria-labelledby="together-heading" class="grid gap-4">
        <h2 id="together-heading" class="text-2xl font-extrabold text-ink">{{ __('Better together') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach (config('marketing.integrations') as [$title, $text])
                <div class="rounded-card border border-line p-5"><p class="font-bold text-ink">{{ __($title) }}</p><p class="mt-1 text-sm text-muted">{{ __($text) }}</p></div>
            @endforeach
        </div>
    </section>
</x-signal.layouts.public>
