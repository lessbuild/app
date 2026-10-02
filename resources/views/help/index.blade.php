<x-signal.layouts.public :title="__('Help centre')" :description="__('Short guides to every part of :app.', ['app' => config('app.name')])" :canonical="route('help')" :structured-data="[\App\Support\StructuredData::breadcrumbs([config('app.name') => route('home'), __('Help centre') => route('help')])]">
    <section class="border-b border-line bg-surface">
        <div class="mx-auto max-w-6xl px-5 py-12 sm:px-8 sm:py-16">
            <p class="ui-eyebrow">{{ __('Help centre') }}</p>
            <h1 class="mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink sm:text-5xl">{{ __('How can we help?') }}</h1>
            <p class="mt-4 max-w-2xl text-base leading-7 text-muted">{{ __('Short, step-by-step guides to every part of :app. For automation, see the', ['app' => config('app.name')]) }} <a href="{{ route('docs.api') }}" class="font-semibold text-primary hover:underline">{{ __('API reference') }}</a>.</p>
            <label class="mt-8 block max-w-xl">
                <span class="sr-only">{{ __('Search the guides') }}</span>
                <x-signal.ui.input type="search" :placeholder="__('Search the guides, e.g. “domain” or “rollback”')" data-help-search autocomplete="off" />
            </label>
        </div>
    </section>

    <div class="mx-auto grid max-w-6xl gap-10 px-5 py-12 sm:px-8 sm:py-16">
        @foreach ($groups as $key => $group)
            <section aria-labelledby="help-{{ $key }}" data-help-group>
                <div class="flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-xl bg-primary-soft text-primary" aria-hidden="true"><x-signal.ui.icon :name="$group['icon']" class="size-5" /></span>
                    <div>
                        <h2 id="help-{{ $key }}" class="text-xl font-extrabold text-ink">{{ __($group['title']) }}</h2>
                        <p class="text-sm text-muted">{{ __($group['summary']) }}</p>
                    </div>
                </div>
                <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($guides->get($key, collect()) as $slug => $guide)
                        <li data-help-guide data-help-text="{{ \Illuminate\Support\Str::lower(__($guide['title']).' '.__($guide['summary']).' '.collect($guide['steps'])->map(fn (array $step): string => __($step[0]).' '.__($step[1]))->implode(' ')) }}">
                            <a href="{{ route('help.guide', $slug) }}" class="ui-card ui-card--interactive block h-full p-5">
                                <span class="block font-extrabold text-ink">{{ __($guide['title']) }}</span>
                                <span class="mt-2 block text-sm leading-6 text-muted">{{ __($guide['summary']) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
        <p class="text-sm text-muted" data-help-empty hidden>{{ __('No guides match that. Try another word, or') }} <a href="mailto:{{ config('legal.contact_email') }}" class="font-semibold text-primary hover:underline">{{ __('email us') }}</a>.</p>
    </div>
</x-signal.layouts.public>
