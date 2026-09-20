@php
    $durationComparison = match (true) {
        $durationDelta === null => __('Unavailable'),
        $durationDelta > 0 => __(':duration slower', ['duration' => \App\Models\Build::formatDuration($durationDelta)]),
        $durationDelta < 0 => __(':duration faster', ['duration' => \App\Models\Build::formatDuration(abs($durationDelta))]),
        default => __('No change'),
    };
@endphp

<div data-build-comparison-content class="space-y-6">
    <div class="ui-alert ui-alert--info p-4">
        {{ __('Compare recorded deployment outcomes and operator context. This view does not fetch source code or contact the repository provider.') }}
    </div>

    <x-ui.insights
        id="build-comparison-insights"
        :summary="__('Build #:id compared with build #:baseline', ['id' => $build->id, 'baseline' => $baseline->id])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.stat
                :label="__('Baseline status')"
                :value="str($baseline->status)->replace('_', ' ')->title()"
                :description="__('Recorded outcome for build #:id.', ['id' => $baseline->id])"
            />
            <x-ui.stat
                :label="__('Current status')"
                :value="str($build->status)->replace('_', ' ')->title()"
                :description="__('Recorded outcome for build #:id.', ['id' => $build->id])"
            />
            <x-ui.stat
                :label="__('Duration change')"
                :value="$durationComparison"
                :description="__('Current build compared with the baseline.')"
            />
            <x-ui.stat
                :label="__('Current revision')"
                :value="$build->shortRevision()"
                :description="__('The immutable revision recorded for the current build.')"
            />
            <x-ui.stat
                :label="__('Current trigger')"
                :value="str($build->trigger_source)->replace('_', ' ')->title()"
                :description="__('How the current deployment was started.')"
            />
        </dl>
    </x-ui.insights>

    <div class="ui-card divide-y divide-primary overflow-hidden" aria-label="{{ __('Deployment comparison') }}">
        @foreach ([
            ['label' => __('Status'), 'baseline' => str($baseline->status)->replace('_', ' ')->title(), 'current' => str($build->status)->replace('_', ' ')->title()],
            ['label' => __('Revision'), 'baseline' => $baseline->shortRevision(), 'current' => $build->shortRevision(), 'revision' => true],
            ['label' => __('Triggered by'), 'baseline' => str($baseline->trigger_source)->title(), 'current' => str($build->trigger_source)->title()],
            ['label' => __('Created'), 'baseline' => $baseline->created_at?->format('Y-m-d H:i:s T') ?? __('Not recorded'), 'current' => $build->created_at?->format('Y-m-d H:i:s T') ?? __('Not recorded')],
            ['label' => __('Started'), 'baseline' => $baseline->started_at?->format('Y-m-d H:i:s T') ?? __('Not started'), 'current' => $build->started_at?->format('Y-m-d H:i:s T') ?? __('Not started')],
            ['label' => __('Finished'), 'baseline' => $baseline->finished_at?->format('Y-m-d H:i:s T') ?? __('Not finished'), 'current' => $build->finished_at?->format('Y-m-d H:i:s T') ?? __('Not finished')],
            ['label' => __('Duration'), 'baseline' => $baseline->durationLabel() ?? __('Not recorded'), 'current' => $build->durationLabel() ?? __('Not recorded'), 'duration' => true],
            ['label' => __('Commit message'), 'baseline' => $baseline->commit_message ?? __('Not recorded'), 'current' => $build->commit_message ?? __('Not recorded'), 'long' => true],
            ['label' => __('Operator note'), 'baseline' => $baseline->operator_note ?? __('Not recorded'), 'current' => $build->operator_note ?? __('Not recorded'), 'long' => true],
            ['label' => __('Failure'), 'baseline' => $baseline->failure_message ?? __('None recorded'), 'current' => $build->failure_message ?? __('None recorded'), 'long' => true],
        ] as $comparison)
            <section data-build-comparison-field class="p-4 sm:p-5">
                <h2 class="text-xs font-bold uppercase tracking-wide text-secondary">{{ $comparison['label'] }}</h2>
                <dl class="mt-3 grid gap-4 sm:grid-cols-2">
                    @foreach ([['label' => __('Baseline Build #:id', ['id' => $baseline->id]), 'build' => $baseline, 'value' => $comparison['baseline']], ['label' => __('Current Build #:id', ['id' => $build->id]), 'build' => $build, 'value' => $comparison['current']]] as $side)
                        <div @class(['min-w-0 rounded-lg bg-secondary p-3' => $comparison['long'] ?? false])>
                            <dt class="text-xs font-semibold text-secondary">
                                <a href="{{ route('builds.show', $side['build']) }}" class="text-primary hover:underline">{{ $side['label'] }}</a>
                            </dt>
                            <dd @class(['mt-2 text-primary', 'whitespace-pre-wrap break-words' => $comparison['long'] ?? false, 'font-mono text-xs' => $comparison['revision'] ?? false])>
                                @if ($comparison['revision'] ?? false)
                                    @if ($revisionUrl = $side['build']->repository->revisionUrl($side['build']->revision))
                                        <a href="{{ $revisionUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ $side['value'] }}</a>
                                    @else
                                        {{ __('Current branch') }}
                                    @endif
                                @else
                                    {{ $side['value'] }}
                                @endif
                                @if (($comparison['duration'] ?? false) && $side['build']->is($build))
                                    @if ($durationDelta !== null)
                                        @if ($durationDelta > 0)
                                            <span class="mt-2 block text-xs text-red-600">{{ __(':duration slower', ['duration' => \App\Models\Build::formatDuration($durationDelta)]) }}</span>
                                        @elseif ($durationDelta < 0)
                                            <span class="mt-2 block text-xs text-green-600">{{ __(':duration faster', ['duration' => \App\Models\Build::formatDuration(abs($durationDelta))]) }}</span>
                                        @else
                                            <span class="mt-2 block text-xs text-secondary">{{ __('No duration change') }}</span>
                                        @endif
                                    @else
                                        <span class="mt-2 block text-xs text-secondary">{{ __('Comparison unavailable') }}</span>
                                    @endif
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>
</div>
