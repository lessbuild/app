@use('App\Data\Projects\SetupStep')
@php($project = $overview->project)
@php($current = $setup->next())
@php($total = count($setup->steps))
@php($position = $current === null ? $total : array_search($current, $setup->steps, true) + 1)
@php($labels = [SetupStep::DONE => __('Done'), SetupStep::WORKING => __('In progress'), SetupStep::TODO => __('To do')])
@php($tones = [SetupStep::DONE => 'success', SetupStep::WORKING => 'info', SetupStep::TODO => 'neutral'])
@php($guides = ['provider' => 'connect-a-provider', 'server' => 'create-a-server', 'website' => 'add-a-website', 'environment' => 'environments-and-variables', 'deploy' => 'deploy-from-git', 'monitor' => 'monitor-uptime', 'analytics' => 'add-analytics'])

@if ($current?->state === SetupStep::WORKING)
    {{-- The current step is finishing by itself (a server, a deploy, the first visit); keep the guide current. --}}
    @push('head')<meta http-equiv="refresh" content="15">@endpush
@endif

{{-- A wizard: one step at a time, with the others summed up in the progress bar and behind "See all steps". --}}
<x-signal.layouts.project :overview="$overview" :title="__('Setup guide')" :description="__('From an empty project to a deployed, monitored and measured site, one step at a time.')">
    <nav aria-label="{{ __('Setup progress') }}">
        <ol class="flex items-center">
            @foreach ($setup->steps as $step)
                @php($isCurrent = $current !== null && $step->key === $current->key)
                <li @class(['flex items-center', 'flex-1' => ! $loop->last]) @if ($isCurrent) aria-current="step" @endif>
                    <span @class([
                        'grid size-8 shrink-0 place-items-center rounded-full border-2 text-xs font-extrabold sm:size-9',
                        'border-success bg-success text-white' => $step->done(),
                        'border-primary bg-primary-soft text-primary ring-4 ring-primary/15' => $isCurrent,
                        'border-line bg-surface text-muted' => ! $step->done() && ! $isCurrent,
                    ]) title="{{ $step->title }}">
                        @if ($step->done())<x-signal.ui.icon name="check" class="size-4" />@else{{ $loop->iteration }}@endif
                        <span class="sr-only">{{ $step->title }} ({{ $labels[$step->state] }})</span>
                    </span>
                    @unless ($loop->last)<span @class(['mx-1 h-0.5 flex-1 rounded-full sm:mx-2', 'bg-success' => $step->done(), 'bg-line' => ! $step->done()]) aria-hidden="true"></span>@endunless
                </li>
            @endforeach
        </ol>
        <p class="mt-3 text-sm font-semibold text-muted">{{ __(':done of :total done', ['done' => $setup->doneCount(), 'total' => $total]) }}</p>
    </nav>

    @if ($current === null)
        <x-signal.ui.panel as="section" class="grid gap-4 p-6 text-center sm:p-10" aria-labelledby="setup-done-heading">
            <span class="mx-auto grid size-14 place-items-center rounded-full bg-success text-white" aria-hidden="true"><x-signal.ui.icon name="check" class="size-7" /></span>
            <h2 id="setup-done-heading" class="text-2xl font-extrabold text-ink">{{ __(':project is set up.', ['project' => $project->name]) }}</h2>
            <p class="mx-auto max-w-xl text-muted">{{ __('It’s deployed, monitored and measuring visits. Every push can deploy from here on, and you’ll hear about it if anything goes down.') }}</p>
            <div class="flex flex-wrap justify-center gap-3">
                <x-signal.ui.button :href="route('projects.show', $project)" variant="primary">{{ __('Go to the project') }}</x-signal.ui.button>
            </div>
        </x-signal.ui.panel>
    @else
        <x-signal.ui.panel as="section" class="grid gap-6 p-6 sm:p-8" aria-labelledby="setup-step-heading">
            <div class="flex items-start gap-4">
                <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-primary-soft text-primary" aria-hidden="true"><x-signal.ui.icon :name="$current->icon" class="size-6" /></span>
                <div class="min-w-0">
                    <p class="ui-eyebrow">{{ __('Step :number of :total', ['number' => $position, 'total' => $total]) }}</p>
                    <h2 id="setup-step-heading" class="mt-1 text-2xl font-extrabold tracking-tight text-ink">{{ $current->title }}</h2>
                    <p class="mt-2 max-w-2xl leading-7 text-muted">{{ $current->description }}</p>
                </div>
            </div>
            @if ($current->detail)
                <p @class(['flex items-center gap-2 rounded-control border px-4 py-3 text-sm font-semibold', 'border-primary/30 bg-primary-soft text-primary' => $current->state === SetupStep::WORKING, 'border-line bg-surface-muted text-ink' => $current->state !== SetupStep::WORKING])>
                    @if ($current->state === SetupStep::WORKING)<span class="size-2 animate-pulse rounded-full bg-primary" aria-hidden="true"></span>@endif
                    {{ $current->detail }}
                </p>
            @endif
            <div class="flex flex-wrap items-center gap-3">
                @if ($canChange && $current->actionUrl)
                    <x-signal.ui.button :href="$current->actionUrl" variant="primary" size="lg">{{ $current->actionLabel }} <x-signal.ui.icon name="arrow-right" class="size-4" /></x-signal.ui.button>
                @elseif (! $canChange)
                    <p class="text-sm text-muted">{{ __('Someone who can change this project needs to do this step.') }}</p>
                @endif
                @isset($guides[$current->key])
                    <x-signal.ui.link :href="route('help.guide', $guides[$current->key])" variant="muted">{{ __('How to do this') }}</x-signal.ui.link>
                @endisset
            </div>
        </x-signal.ui.panel>
    @endif

    <x-signal.ui.disclosure :title="__('See all steps')">
        <ol class="grid gap-3">
            @foreach ($setup->steps as $step)
                <li class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span @class(['mt-0.5 grid size-6 shrink-0 place-items-center rounded-full text-[11px] font-extrabold', 'bg-success text-white' => $step->done(), 'bg-surface-muted text-muted' => ! $step->done()]) aria-hidden="true">@if ($step->done())✓@else{{ $loop->iteration }}@endif</span>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-ink">{{ $step->title }}</p>
                            <p class="text-xs text-muted">{{ $step->detail ?? $step->description }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-signal.ui.badge :tone="$tones[$step->state]">{{ $labels[$step->state] }}</x-signal.ui.badge>
                        @if ($step->actionUrl && ($canChange || $step->done()))
                            <x-signal.ui.link :href="$step->actionUrl" variant="primary" size="sm">{{ $step->actionLabel }}</x-signal.ui.link>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </x-signal.ui.disclosure>
</x-signal.layouts.project>
