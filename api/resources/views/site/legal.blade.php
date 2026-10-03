@php($other = $page === 'privacy' ? 'terms' : 'privacy')

<x-signal.layouts.public :title="__($copy['title'])" :description="__($copy['description'])" :canonical="route('legal', $page)" :structured-data="[\App\Support\StructuredData::breadcrumbs([config('app.name') => route('home'), __($copy['title']) => route('legal', $page)])]">
    <article class="mx-auto max-w-3xl px-5 py-12 sm:px-8 sm:py-16" aria-labelledby="legal-heading">
        <p class="ui-eyebrow">{{ __(':app legal', ['app' => config('app.name')]) }}</p>
        <h1 id="legal-heading" class="mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink sm:text-5xl">{{ __($copy['title']) }}</h1>
        <p class="mt-4 text-base leading-7 text-muted">{{ __($copy['description']) }}</p>
        <p class="mt-3 text-sm font-semibold text-subtle">{{ __('Effective :date', ['date' => \Illuminate\Support\Carbon::parse(config('legal.effective_date'))->isoFormat('D MMMM YYYY')]) }}</p>

        <div class="mt-10 grid gap-8">
            @foreach ($copy['sections'] as [$heading, $text])
                <section>
                    <h2 class="text-lg font-extrabold text-ink">{{ __($heading) }}</h2>
                    <p class="mt-2 leading-7 text-muted">{{ __($text) }}</p>
                </section>
            @endforeach
            <section>
                <h2 class="text-lg font-extrabold text-ink">{{ __('Contact') }}</h2>
                <p class="mt-2 leading-7 text-muted">{{ __('Questions and requests about your information can be sent to') }} <a href="mailto:{{ config('legal.contact_email') }}" class="font-semibold text-primary hover:underline">{{ config('legal.contact_email') }}</a>.</p>
            </section>
        </div>

        <p class="mt-12 border-t border-line pt-6 text-sm text-muted">
            {{ __('See also') }} <a href="{{ route('legal', $other) }}" class="font-semibold text-primary hover:underline">{{ __(config('legal.pages.'.$other.'.title')) }}</a>.
        </p>
    </article>
</x-signal.layouts.public>
