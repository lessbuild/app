@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$project->name" :description="$project->description">
    @if ($checklist !== [])
        @php($done = count(array_filter($checklist, fn ($step) => $step->done)))
        <x-signal.ui.panel as="section" class="space-y-4 p-5 sm:p-6" aria-labelledby="checklist-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __(':done of :total done', ['done' => $done, 'total' => count($checklist)]) }}</p>
                    <h2 id="checklist-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Get :project going', ['project' => $project->name]) }}</h2>
                </div>
                <form method="POST" action="{{ route('projects.checklist.dismiss', $project) }}">
                    @csrf
                    @method('DELETE')
                    <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Hide this') }}</x-signal.ui.button>
                </form>
            </div>
            <x-signal.ui.progress :value="$done" :max="count($checklist)" :label="__('Getting started progress')" />
            <ol class="grid gap-2">
                @foreach ($checklist as $step)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-panel border border-line bg-surface px-4 py-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <span @class(['mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full border text-[11px] font-extrabold', 'border-success bg-success text-white' => $step->done, 'border-line text-muted' => ! $step->done]) aria-hidden="true">{{ $step->done ? '✓' : $loop->iteration }}</span>
                            <div class="min-w-0">
                                <p @class(['text-sm font-bold', 'text-muted line-through' => $step->done, 'text-ink' => ! $step->done])>{{ $step->label }}<span class="sr-only">{{ $step->done ? __(' (done)') : '' }}</span></p>
                                <p class="text-xs text-muted">{{ $step->description }}</p>
                            </div>
                        </div>
                        @if (! $step->done && $step->actionUrl)
                            <x-signal.ui.button :href="$step->actionUrl" variant="secondary" size="sm">{{ $step->actionLabel }}</x-signal.ui.button>
                        @endif
                    </li>
                @endforeach
            </ol>
        </x-signal.ui.panel>
    @endif

    <section aria-labelledby="services-heading" class="space-y-4">
        <h2 id="services-heading" class="text-lg font-extrabold text-ink">{{ __('Services') }}</h2>
        <ul class="grid gap-4 sm:grid-cols-2">
            @foreach ($overview->services as $service)
                <li>@include('projects._service-card', ['service' => $service, 'project' => $project])</li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="environments-heading" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="environments-heading" class="text-lg font-extrabold text-ink">{{ __('Environments') }}</h2>
            @if ($overview->canManage)
                <x-signal.ui.button :href="route('projects.settings', $project).'#environments'" variant="quiet" size="sm">{{ __('Manage environments') }}</x-signal.ui.button>
            @endif
        </div>
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line">
                @foreach ($overview->environments as $environment)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <span class="font-bold text-ink">{{ $environment->name }}</span>
                        <x-signal.ui.badge :tone="$environment->kind === \App\Domain\Projects\Enums\EnvironmentKind::Production ? 'accent' : 'neutral'">{{ $environment->kind->label() }}</x-signal.ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    </section>
</x-signal.layouts.project>
