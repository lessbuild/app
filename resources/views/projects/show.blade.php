@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$project->name" :description="$project->description">
    @if ($setup !== null && ! $setup->complete())
        @php($next = $setup->next())
        <x-signal.ui.panel as="section" class="space-y-4 p-5 sm:p-6" aria-labelledby="checklist-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="ui-eyebrow">{{ __(':done of :total done', ['done' => $setup->doneCount(), 'total' => count($setup->steps)]) }}</p>
                    <h2 id="checklist-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Get :project going', ['project' => $project->name]) }}</h2>
                    @if ($next)<p class="mt-1 text-sm text-muted">{{ __('Next: :step', ['step' => $next->title]) }}@if ($next->detail) — {{ $next->detail }}@endif</p>@endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($next?->actionUrl)<x-signal.ui.button :href="$next->actionUrl" variant="primary" size="sm">{{ $next->actionLabel }}</x-signal.ui.button>@endif
                    <x-signal.ui.button :href="route('projects.setup', $project)" variant="secondary" size="sm">{{ __('Open the setup guide') }}</x-signal.ui.button>
                    <form method="POST" action="{{ route('projects.checklist.dismiss', $project) }}">
                        @csrf
                        @method('DELETE')
                        <x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Hide this') }}</x-signal.ui.button>
                    </form>
                </div>
            </div>
            <x-signal.ui.progress :value="$setup->doneCount()" :max="count($setup->steps)" :label="__('Setup progress')" />
            <ol class="flex flex-wrap gap-2" aria-label="{{ __('Setup steps') }}">
                @foreach ($setup->steps as $step)
                    <li @class(['ui-chip inline-flex items-center gap-1.5', 'text-success' => $step->done()])>
                        @if ($step->done())<x-signal.ui.icon name="check" class="size-3.5" /><span class="sr-only">{{ __('Done:') }}</span>@else<span class="text-xs font-extrabold text-muted">{{ $loop->iteration }}</span>@endif
                        {{ $step->title }}
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
                        <x-signal.ui.badge :tone="$environment->kind === \App\Enums\EnvironmentKind::Production ? 'accent' : 'neutral'">{{ $environment->kind->label() }}</x-signal.ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    </section>

    <section aria-labelledby="activity-heading" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="activity-heading" class="text-lg font-extrabold text-ink">{{ __('Recent activity') }}</h2>
            @if ($canViewAuditLog)
                <x-signal.ui.button :href="route('account.audit-log', ['project' => $project->id])" variant="quiet" size="sm">{{ __('Full history') }}</x-signal.ui.button>
            @endif
        </div>
        <div id="activity-live" data-live-region data-live-interval="20000">
            <x-signal.ui.card class="overflow-hidden">
                @if ($activity === [])
                    <p class="px-5 py-4 text-sm text-muted">{{ __('Nothing has happened here yet.') }}</p>
                @else
                    <ol class="divide-y divide-line">
                        @foreach ($activity as $entry)
                            <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-5 py-3 text-sm">
                                <span class="min-w-0"><span class="font-bold text-ink">{{ $entry->actor }}</span> <span class="text-muted">{{ \Illuminate\Support\Str::lcfirst($entry->description) }}</span></span>
                                <time class="shrink-0 text-xs text-muted" datetime="{{ $entry->at->toIso8601String() }}" title="{{ $entry->at->toDayDateTimeString() }}">{{ $entry->at->diffForHumans() }}</time>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-signal.ui.card>
        </div>
    </section>
</x-signal.layouts.project>
