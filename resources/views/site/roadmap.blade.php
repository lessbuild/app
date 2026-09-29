{{-- The public roadmap: what's being built, what's next, what people are asking for, and what shipped lately. --}}
@php($headings = ['in_progress' => __('In progress'), 'planned' => __('Planned'), 'under_review' => __('Under consideration')])
@php($blurbs = ['in_progress' => __('Being built now.'), 'planned' => __('Coming next.'), 'under_review' => __('Requests we’re weighing up. Vote for the ones you want.')])
<x-signal.layouts.public :title="__('Roadmap')" :description="__('What’s being built for :app, what’s next, and what people are asking for.', ['app' => config('app.name')])" :canonical="route('roadmap')">
    <div class="mx-auto grid max-w-6xl gap-10 px-5 py-12 sm:px-8 sm:py-16">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div class="max-w-2xl">
                <p class="ui-eyebrow">{{ __('Roadmap') }}</p>
                <h1 class="mt-3 text-4xl font-extrabold tracking-[-0.04em] text-ink sm:text-5xl">{{ __('What we’re building') }}</h1>
                <p class="mt-4 text-base leading-7 text-muted">{{ __('Written up from your feedback. Vote for what you want next; you’ll hear from us when it ships.') }}</p>
            </div>
            @auth
                <x-signal.ui.button :href="route('roadmap', ['dialog' => 'suggest-feature'])" variant="primary" data-modal-trigger="suggest-feature">{{ __('Suggest a feature') }}</x-signal.ui.button>
            @else
                <x-signal.ui.button :href="route('login')" variant="secondary">{{ __('Sign in to vote or suggest') }}</x-signal.ui.button>
            @endauth
        </div>

        @foreach (['feedback', 'status'] as $flash)
            @if (session($flash))<x-signal.ui.alert tone="success" role="status">{{ session($flash) }}</x-signal.ui.alert>@endif
        @endforeach

        <div class="grid items-start gap-6 lg:grid-cols-3">
            @foreach ($columns as $status => $requests)
                <section class="grid gap-3" aria-labelledby="column-{{ $status }}">
                    <div>
                        <h2 id="column-{{ $status }}" class="flex items-center gap-2 text-lg font-extrabold text-ink">{{ $headings[$status] }} <x-signal.ui.badge>{{ $requests->count() }}</x-signal.ui.badge></h2>
                        <p class="text-sm text-muted">{{ $blurbs[$status] }}</p>
                    </div>
                    @forelse ($requests as $item)
                        <x-signal.ui.card as="article" id="request-{{ $item->id }}" class="flex items-start gap-3 p-4">
                            @php($mine = in_array($item->id, $voted, true))
                            @auth
                                <form method="POST" action="{{ route('roadmap.vote', $item->id) }}">
                                    @csrf
                                    <button type="submit" @class(['grid min-w-12 place-items-center rounded-control border px-2 py-1.5 text-center transition', 'border-primary bg-primary-soft text-primary' => $mine, 'border-line text-muted hover:border-primary hover:text-primary' => ! $mine]) aria-pressed="{{ $mine ? 'true' : 'false' }}" aria-label="{{ $mine ? __('Take back your vote for :title', ['title' => $item->title]) : __('Vote for :title', ['title' => $item->title]) }}">
                                        <x-signal.ui.icon name="arrow-up-right" class="size-4 -rotate-45" />
                                        <span class="text-sm font-extrabold tabular-nums">{{ $item->votes_count }}</span>
                                    </button>
                                </form>
                            @else
                                <span class="grid min-w-12 place-items-center rounded-control border border-line px-2 py-1.5 text-center text-muted">
                                    <span class="text-sm font-extrabold tabular-nums">{{ $item->votes_count }}</span>
                                    <span class="text-[11px]">{{ trans_choice('vote|votes', $item->votes_count) }}</span>
                                </span>
                            @endauth
                            <div class="min-w-0">
                                <h3 class="font-bold text-ink">{{ $item->title }}</h3>
                                @if ($item->description)<p class="mt-1 whitespace-pre-line text-sm leading-6 text-muted">{{ $item->description }}</p>@endif
                            </div>
                        </x-signal.ui.card>
                    @empty
                        <p class="rounded-panel border border-dashed border-line p-4 text-sm text-muted">{{ __('Nothing here right now.') }}</p>
                    @endforelse
                </section>
            @endforeach
        </div>

        <section id="shipped" class="grid gap-3" aria-labelledby="shipped-heading">
            <h2 id="shipped-heading" class="text-lg font-extrabold text-ink">{{ __('Recently shipped') }}</h2>
            @forelse ($shipped as $item)
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm">
                    <x-signal.ui.icon name="check" class="size-4 shrink-0 translate-y-0.5 text-success" />
                    <span class="font-bold text-ink">{{ $item->title }}</span>
                    <span class="text-muted">{{ $item->shipped_at?->isoFormat('D MMMM YYYY') }} · {{ trans_choice(':count vote|:count votes', $item->votes_count, ['count' => $item->votes_count]) }}</span>
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('Nothing shipped from the roadmap in the last 90 days.') }}</p>
            @endforelse
            <p class="text-sm"><a href="{{ route('changelog') }}" class="font-bold text-primary underline">{{ __('Everything that’s changed is in the changelog') }}</a></p>
        </section>
    </div>

    @auth
        <x-signal.overlays.modal id="suggest-feature" :title="__('Suggest a feature')" :description="__('Tell us what you’d like and why. We read every suggestion and write the popular ones up here.')" :open="$errors->hasAny(['feedback_message'])">
            <form method="POST" action="{{ route('feedback.store') }}" class="grid gap-4">
                @csrf
                <input type="hidden" name="feedback_kind" value="idea">
                <input type="hidden" name="feedback_page" value="{{ route('roadmap') }}">
                <x-signal.ui.textarea-field id="suggest-feature-message" name="feedback_message" :label="__('Your idea')" rows="5" maxlength="5000" required />
                <div class="flex justify-end"><x-signal.ui.button type="submit" variant="primary">{{ __('Send suggestion') }}</x-signal.ui.button></div>
            </form>
        </x-signal.overlays.modal>
    @endauth
</x-signal.layouts.public>
