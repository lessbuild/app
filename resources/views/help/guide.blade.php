<x-signal.layouts.public :title="__($guide['title'])" :description="__($guide['summary'])" :canonical="route('help.guide', $slug)" :structured-data="[\App\Support\StructuredData::breadcrumbs([config('app.name') => route('home'), __('Help centre') => route('help'), __($guide['title']) => route('help.guide', $slug)]), \App\Support\StructuredData::article(__($guide['title']), __($guide['summary']), route('help.guide', $slug))]">
    <div class="mx-auto grid max-w-6xl gap-10 px-5 py-12 sm:px-8 sm:py-16 lg:grid-cols-[1fr_18rem]">
        <article aria-labelledby="guide-heading">
            <x-signal.ui.link :href="route('help')" layout="inline" size="inline" variant="muted" class="font-bold"><span aria-hidden="true">←</span> {{ __('Help centre') }}</x-signal.ui.link>
            <p class="ui-eyebrow mt-6">{{ __($group['title']) }}</p>
            <h1 id="guide-heading" class="mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink">{{ __($guide['title']) }}</h1>
            <p class="mt-4 text-base leading-7 text-muted">{{ __($guide['summary']) }}</p>
            <ol class="mt-8 grid gap-4">
                @foreach ($guide['steps'] as [$title, $text])
                    <li class="ui-card flex gap-4 p-5">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-primary-soft text-xs font-extrabold text-primary">{{ $loop->iteration }}</span>
                        <div>
                            <h2 class="font-extrabold text-ink">{{ __($title) }}</h2>
                            <p class="mt-1 text-sm leading-6 text-muted">{{ __($text) }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
            <p class="mt-8 text-sm text-muted">{{ __('Still stuck?') }} <a href="mailto:{{ config('legal.contact_email') }}" class="font-semibold text-primary hover:underline">{{ __('Email us') }}</a>{{ __(', or send feedback from inside the app.') }}</p>
        </article>
        @if ($related->isNotEmpty())
            <aside aria-labelledby="related-heading">
                <h2 id="related-heading" class="text-xs font-extrabold uppercase tracking-[0.16em] text-subtle">{{ __('More about :group', ['group' => __($group['title'])]) }}</h2>
                <ul class="mt-4 grid gap-2">
                    @foreach ($related as $key => $other)
                        <li><a href="{{ route('help.guide', $key) }}" class="block rounded-control px-3 py-2 text-sm font-semibold text-muted hover:bg-surface-muted hover:text-ink">{{ __($other['title']) }}</a></li>
                    @endforeach
                </ul>
            </aside>
        @endif
    </div>
</x-signal.layouts.public>
