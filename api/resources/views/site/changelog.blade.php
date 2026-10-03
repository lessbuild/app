<x-signal.layouts.public :title="__('Changelog')" :description="__('Every :app release: new features and improvements to deploys, servers, monitoring, security and analytics, newest first.', ['app' => config('app.name')])" :canonical="route('changelog')" :structured-data="[\App\Support\StructuredData::breadcrumbs([config('app.name') => route('home'), __('Changelog') => route('changelog')])]">
    <div class="mx-auto max-w-3xl px-5 py-12 sm:px-8 sm:py-16">
        <p class="ui-eyebrow">{{ __('Changelog') }}</p>
        <h1 class="mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink sm:text-5xl">{{ __('What’s new') }}</h1>
        <p class="mt-4 text-base leading-7 text-muted">{{ __('Improvements to :app, newest first.', ['app' => config('app.name')]) }}</p>
        <ol class="mt-10 grid gap-10 border-l border-line pl-6">
            @foreach ($entries as $entry)
                <li class="relative">
                    <span class="absolute -left-[31px] top-1.5 size-3 rounded-full border-2 border-surface bg-primary" aria-hidden="true"></span>
                    <time class="text-sm font-semibold text-subtle" datetime="{{ $entry['date'] }}">{{ \Illuminate\Support\Carbon::parse($entry['date'])->isoFormat('D MMMM YYYY') }}</time>
                    <h2 class="mt-1 text-xl font-extrabold text-ink">{{ __($entry['title']) }}</h2>
                    <ul class="mt-3 grid gap-2">
                        @foreach ($entry['changes'] as $change)
                            <li class="flex gap-2 text-sm leading-6 text-muted"><x-signal.ui.icon name="check" class="mt-1 size-4 shrink-0 text-success" /><span>{{ __($change) }}</span></li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ol>
    </div>
</x-signal.layouts.public>
