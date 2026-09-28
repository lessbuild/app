@php($project = $overview->project)
@php($next = $setup->next())
@php($total = count($setup->steps))
@php($working = collect($setup->steps)->contains(fn ($step) => $step->state === \App\Data\Projects\SetupStep::WORKING))
@php($labels = [\App\Data\Projects\SetupStep::DONE => __('Done'), \App\Data\Projects\SetupStep::WORKING => __('In progress'), \App\Data\Projects\SetupStep::TODO => __('To do')])
@php($tones = [\App\Data\Projects\SetupStep::DONE => 'success', \App\Data\Projects\SetupStep::WORKING => 'info', \App\Data\Projects\SetupStep::TODO => 'neutral'])

@if ($working)
    {{-- Something is finishing by itself (a server, a deploy, the first visit); keep the guide current. --}}
    @push('head')<meta http-equiv="refresh" content="15">@endpush
@endif

<x-signal.layouts.project :overview="$overview" :title="__('Setup guide')" :description="__('From an empty project to a deployed, monitored and measured site. Steps tick themselves off as you go.')">
    <x-signal.ui.panel as="section" class="grid gap-5 p-5 sm:p-6" aria-labelledby="setup-progress-heading">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __(':done of :total done', ['done' => $setup->doneCount(), 'total' => $total]) }}</p>
                <h2 id="setup-progress-heading" class="mt-1 text-xl font-extrabold text-ink">
                    {{ $next === null ? __(':project is set up.', ['project' => $project->name]) : __('Next: :step', ['step' => $next->title]) }}
                </h2>
                @if ($next?->detail)<p class="mt-1 text-sm text-muted">{{ $next->detail }}</p>@elseif ($next)<p class="mt-1 text-sm text-muted">{{ $next->description }}</p>@endif
            </div>
            @if ($canChange && $next?->actionUrl)
                <x-signal.ui.button :href="$next->actionUrl" variant="primary" size="lg">{{ $next->actionLabel }} <x-signal.ui.icon name="arrow-right" class="size-4" /></x-signal.ui.button>
            @endif
        </div>
        <x-signal.ui.progress :value="$setup->doneCount()" :max="$total" :label="__('Setup progress')" />
    </x-signal.ui.panel>

    <ol class="relative grid gap-4" aria-label="{{ __('Setup steps') }}">
        @foreach ($setup->steps as $step)
            @php($isNext = $next !== null && $step->key === $next->key)
            <li class="relative flex gap-4">
                <div class="flex flex-col items-center" aria-hidden="true">
                    <span @class([
                        'grid size-10 shrink-0 place-items-center rounded-full border-2 text-sm font-extrabold',
                        'border-success bg-success text-white' => $step->done(),
                        'border-primary bg-primary-soft text-primary' => ! $step->done() && ($isNext || $step->state === \App\Data\Projects\SetupStep::WORKING),
                        'border-line bg-surface text-muted' => ! $step->done() && ! $isNext && $step->state !== \App\Data\Projects\SetupStep::WORKING,
                    ])>
                        @if ($step->done())<x-signal.ui.icon name="check" class="size-5" />@else{{ $loop->iteration }}@endif
                    </span>
                    @unless ($loop->last)<span @class(['mt-2 w-0.5 flex-1', 'bg-success' => $step->done(), 'bg-line' => ! $step->done()])></span>@endunless
                </div>
                <x-signal.ui.card @class(['mb-2 flex-1 p-4 sm:p-5', 'ring-2 ring-primary/30' => $isNext])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-surface-muted text-primary" aria-hidden="true"><x-signal.ui.icon :name="$step->icon" class="size-4" /></span>
                            <div class="min-w-0">
                                <h3 class="font-extrabold text-ink"><span class="sr-only">{{ __('Step :number:', ['number' => $loop->iteration]) }} </span>{{ $step->title }}</h3>
                                <p class="mt-1 text-sm leading-6 text-muted">{{ $step->description }}</p>
                                @if ($step->detail)
                                    <p @class(['mt-2 inline-flex items-center gap-2 text-sm font-semibold', 'text-success' => $step->done(), 'text-primary' => ! $step->done()])>
                                        @if ($step->state === \App\Data\Projects\SetupStep::WORKING)<span class="size-2 animate-pulse rounded-full bg-primary" aria-hidden="true"></span>@endif
                                        {{ $step->detail }}
                                    </p>
                                @endif
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <x-signal.ui.badge :tone="$tones[$step->state]">{{ $labels[$step->state] }}</x-signal.ui.badge>
                            @if ($step->actionUrl && ($canChange || $step->done()))
                                <x-signal.ui.button :href="$step->actionUrl" :variant="$isNext ? 'primary' : 'secondary'" size="sm">{{ $step->actionLabel }}</x-signal.ui.button>
                            @endif
                        </div>
                    </div>
                </x-signal.ui.card>
            </li>
        @endforeach
    </ol>

    <p class="text-sm text-muted">{{ __('Stuck on a step?') }} <a href="{{ route('help') }}" class="font-semibold text-primary hover:underline">{{ __('The help centre has a guide for each one.') }}</a></p>
</x-signal.layouts.project>
