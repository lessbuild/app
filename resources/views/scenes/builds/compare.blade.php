<x-layouts.app>
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Build #:id', ['id' => $build->id])"
        :route="route('builds.show', $build)"
    />

    <x-layouts.partials.heading
        :title="__('Compare deployments')"
        :description="$build->repository->name"
    >
        <x-slot:buttons>
            <x-ui.button :href="route('builds.compare', ['build' => $baseline, 'baseline' => $build])" variant="secondary">
                {{ __('Swap comparison') }}
            </x-ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <div class="ui-alert ui-alert--info mt-6 p-4">
        {{ __('Compare recorded deployment outcomes and operator context. This view does not fetch source code or contact the repository provider.') }}
    </div>

    @php
        $durationComparison = match (true) {
            $durationDelta === null => __('Unavailable'),
            $durationDelta > 0 => __(':duration slower', ['duration' => \App\Models\Build::formatDuration($durationDelta)]),
            $durationDelta < 0 => __(':duration faster', ['duration' => \App\Models\Build::formatDuration(abs($durationDelta))]),
            default => __('No change'),
        };
    @endphp

    <x-ui.insights
        id="build-comparison-insights"
        class="mt-6"
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

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="min-w-full divide-y divide-primary text-sm">
            <thead>
                <tr>
                    <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Attribute') }}</th>
                    <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">
                        <a href="{{ route('builds.show', $baseline) }}" class="text-primary hover:underline">
                            {{ __('Baseline Build #:id', ['id' => $baseline->id]) }}
                        </a>
                    </th>
                    <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">
                        <a href="{{ route('builds.show', $build) }}" class="text-primary hover:underline">
                            {{ __('Current Build #:id', ['id' => $build->id]) }}
                        </a>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary">
                <tr>
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Status') }}</th>
                    <td class="px-4 py-3 text-primary">{{ str($baseline->status)->replace('_', ' ')->title() }}</td>
                    <td class="px-4 py-3 text-primary">{{ str($build->status)->replace('_', ' ')->title() }}</td>
                </tr>
                <tr>
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Revision') }}</th>
                    @foreach ([$baseline, $build] as $comparedBuild)
                        <td class="px-4 py-3 font-mono text-primary">
                            @if ($revisionUrl = $comparedBuild->repository->revisionUrl($comparedBuild->revision))
                                <a href="{{ $revisionUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">
                                    {{ $comparedBuild->shortRevision() }}
                                </a>
                            @else
                                {{ __('Current branch') }}
                            @endif
                        </td>
                    @endforeach
                </tr>
                <tr>
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Triggered by') }}</th>
                    <td class="px-4 py-3 text-primary">{{ str($baseline->trigger_source)->title() }}</td>
                    <td class="px-4 py-3 text-primary">{{ str($build->trigger_source)->title() }}</td>
                </tr>
                <tr>
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Created') }}</th>
                    <td class="px-4 py-3 text-primary">{{ $baseline->created_at?->format('Y-m-d H:i:s T') ?? __('Not recorded') }}</td>
                    <td class="px-4 py-3 text-primary">{{ $build->created_at?->format('Y-m-d H:i:s T') ?? __('Not recorded') }}</td>
                </tr>
                <tr>
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Started') }}</th>
                    <td class="px-4 py-3 text-primary">{{ $baseline->started_at?->format('Y-m-d H:i:s T') ?? __('Not started') }}</td>
                    <td class="px-4 py-3 text-primary">{{ $build->started_at?->format('Y-m-d H:i:s T') ?? __('Not started') }}</td>
                </tr>
                <tr>
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Finished') }}</th>
                    <td class="px-4 py-3 text-primary">{{ $baseline->finished_at?->format('Y-m-d H:i:s T') ?? __('Not finished') }}</td>
                    <td class="px-4 py-3 text-primary">{{ $build->finished_at?->format('Y-m-d H:i:s T') ?? __('Not finished') }}</td>
                </tr>
                <tr>
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Duration') }}</th>
                    <td class="px-4 py-3 text-primary">{{ $baseline->durationLabel() ?? __('Not recorded') }}</td>
                    <td class="px-4 py-3 text-primary">
                        {{ $build->durationLabel() ?? __('Not recorded') }}
                        @if ($durationDelta !== null)
                            @if ($durationDelta > 0)
                                <span class="ml-2 text-xs text-red-600">{{ __(':duration slower', ['duration' => \App\Models\Build::formatDuration($durationDelta)]) }}</span>
                            @elseif ($durationDelta < 0)
                                <span class="ml-2 text-xs text-green-600">{{ __(':duration faster', ['duration' => \App\Models\Build::formatDuration(abs($durationDelta))]) }}</span>
                            @else
                                <span class="ml-2 text-xs text-secondary">{{ __('No duration change') }}</span>
                            @endif
                        @else
                            <span class="ml-2 text-xs text-secondary">{{ __('Comparison unavailable') }}</span>
                        @endif
                    </td>
                </tr>
                <tr class="align-top">
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Commit message') }}</th>
                    <td class="whitespace-pre-wrap break-words px-4 py-3 text-primary">{{ $baseline->commit_message ?? __('Not recorded') }}</td>
                    <td class="whitespace-pre-wrap break-words px-4 py-3 text-primary">{{ $build->commit_message ?? __('Not recorded') }}</td>
                </tr>
                <tr class="align-top">
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Operator note') }}</th>
                    <td class="whitespace-pre-wrap break-words px-4 py-3 text-primary">{{ $baseline->operator_note ?? __('Not recorded') }}</td>
                    <td class="whitespace-pre-wrap break-words px-4 py-3 text-primary">{{ $build->operator_note ?? __('Not recorded') }}</td>
                </tr>
                <tr class="align-top">
                    <th scope="row" class="px-4 py-3 text-left font-medium text-secondary">{{ __('Failure') }}</th>
                    <td class="whitespace-pre-wrap break-words px-4 py-3 text-primary">{{ $baseline->failure_message ?? __('None recorded') }}</td>
                    <td class="whitespace-pre-wrap break-words px-4 py-3 text-primary">{{ $build->failure_message ?? __('None recorded') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</x-layouts.app>
