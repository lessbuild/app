<x-signal.layouts.public :title="$service->name()" :description="__($copy['summary'])" :canonical="route('features', $service->key())">
    <div class="mx-auto grid max-w-6xl gap-12 px-5 py-12 sm:px-8 sm:py-16">
    <section class="grid gap-4">
        <p class="ui-eyebrow">{{ __($copy['eyebrow']) }} · {{ $service->name() }}</p>
        <h1 class="max-w-3xl text-4xl font-extrabold tracking-tight text-ink">{{ __($copy['headline']) }}</h1>
        <p class="max-w-2xl text-lg text-muted">{{ __($copy['summary']) }}</p>
        <div class="flex flex-wrap gap-3">
            <x-signal.ui.button :href="route('register')" variant="primary">{{ __('Start free') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('pricing').'#'.$service->key()" variant="secondary">{{ __(':service pricing', ['service' => $service->name()]) }}</x-signal.ui.button>
        </div>
    </section>

    @foreach ($copy['groups'] as [$group, $features])
        <section class="grid gap-4" aria-label="{{ __($group) }}">
            <h2 class="text-2xl font-extrabold text-ink">{{ __($group) }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($features as [$title, $text])
                    <div class="rounded-card border border-line p-5"><p class="font-bold text-ink">{{ __($title) }}</p><p class="mt-1 text-sm text-muted">{{ __($text) }}</p></div>
                @endforeach
            </div>
        </section>
    @endforeach

    @if ($copy['questions'] !== [])
        <section class="grid gap-3" aria-labelledby="questions-heading">
            <h2 id="questions-heading" class="text-2xl font-extrabold text-ink">{{ __('Questions') }}</h2>
            @foreach ($copy['questions'] as [$question, $answer])
                <x-signal.ui.disclosure :title="__($question)"><p class="text-sm text-muted">{{ __($answer) }}</p></x-signal.ui.disclosure>
            @endforeach
        </section>
    @endif
    </div>
</x-signal.layouts.public>
