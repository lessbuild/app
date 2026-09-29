<x-signal.layouts.public :title="__(':app vs :other', ['app' => config('app.name'), 'other' => $copy['name']])" :description="__('How :app compares with :other, and when to choose each.', ['app' => config('app.name'), 'other' => $copy['name']])" :canonical="route('compare', $slug)">
    <div class="mx-auto max-w-4xl px-5 py-12 sm:px-8 sm:py-16">
        <p class="ui-eyebrow">{{ __('Compare') }}</p>
        <h1 class="mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink sm:text-5xl">{{ __(':app vs :other', ['app' => config('app.name'), 'other' => $copy['name']]) }}</h1>
        <p class="mt-4 max-w-2xl text-base leading-7 text-muted">{{ __($copy['summary']) }} {{ __(':app puts deploys, servers, monitoring and analytics in one account.', ['app' => config('app.name')]) }}</p>

        <div class="mt-10 grid gap-5 md:grid-cols-2">
            <x-signal.ui.card as="section" class="p-6" aria-labelledby="same-heading">
                <h2 id="same-heading" class="font-extrabold text-ink">{{ __('What’s alike') }}</h2>
                <ul class="mt-4 grid gap-3">
                    @foreach ($copy['same'] as $line)
                        <li class="flex gap-2 text-sm leading-6 text-muted"><x-signal.ui.icon name="check" class="mt-1 size-4 shrink-0 text-subtle" /><span>{{ __($line) }}</span></li>
                    @endforeach
                </ul>
            </x-signal.ui.card>
            <x-signal.ui.card as="section" class="p-6" aria-labelledby="different-heading">
                <h2 id="different-heading" class="font-extrabold text-ink">{{ __('Where :app is different', ['app' => config('app.name')]) }}</h2>
                <ul class="mt-4 grid gap-3">
                    @foreach ($copy['different'] as $line)
                        <li class="flex gap-2 text-sm leading-6 text-ink"><x-signal.ui.icon name="check" class="mt-1 size-4 shrink-0 text-success" /><span>{{ __($line) }}</span></li>
                    @endforeach
                </ul>
            </x-signal.ui.card>
        </div>

        <x-signal.ui.card as="section" class="mt-5 p-6" aria-labelledby="choose-heading">
            <h2 id="choose-heading" class="font-extrabold text-ink">{{ __('Which to choose') }}</h2>
            <p class="mt-3 text-sm leading-6 text-muted"><span class="font-semibold text-ink">{{ __('Choose :other if', ['other' => $copy['name']]) }}</span> {{ lcfirst(__($copy['choose_them'])) }}</p>
            <p class="mt-2 text-sm leading-6 text-muted"><span class="font-semibold text-ink">{{ __('Choose :app if', ['app' => config('app.name')]) }}</span> {{ __('you want deploys, the servers they run on, monitoring and analytics to work together, on one bill.') }}</p>
            <div class="mt-5 flex flex-wrap gap-3">
                <x-signal.ui.button :href="route('register')" variant="primary">{{ __('Start free') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('pricing')" variant="secondary">{{ __('See pricing') }}</x-signal.ui.button>
            </div>
        </x-signal.ui.card>

        <p class="mt-8 text-xs text-subtle">{{ __(':other is a trademark of its owner. This page is based on their public information as of :date; products change, so check their site for the latest.', ['other' => $copy['name'], 'date' => $checked]) }}</p>
    </div>
</x-signal.layouts.public>
