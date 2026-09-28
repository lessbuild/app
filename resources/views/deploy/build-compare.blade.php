@php($project = $overview->project)
@php($baseline = $comparison?->baseline)
@php($seconds = fn (?\App\Models\Build $b): string => $b?->started_at && $b->finished_at ? \Carbon\CarbonInterval::seconds((int) $b->started_at->diffInSeconds($b->finished_at, true))->cascade()->forHumans(short: true) : '—')
@php($kindTones = ['added' => 'success', 'removed' => 'danger', 'changed' => 'warning'])

<x-signal.layouts.project :overview="$overview" :title="__('Compare deploy #:id', ['id' => $build->id])" :description="$build->repository->name.' → '.$build->website->name">
    <form method="GET" class="flex flex-wrap items-end gap-3">
        <x-signal.ui.select-field id="compare-with" name="with" :label="__('Compare with')" onchange="this.form.requestSubmit()">
            @foreach ($candidates as $candidate)
                <option value="{{ $candidate->id }}" @selected($baseline?->id === $candidate->id)>#{{ $candidate->id }} · {{ __(ucfirst($candidate->status)) }} · {{ $candidate->shortRevision() ?? '—' }} · {{ \Illuminate\Support\Str::limit($candidate->commit_message ?? '', 50) }}</option>
            @endforeach
        </x-signal.ui.select-field>
        <noscript><x-signal.ui.button type="submit" variant="secondary">{{ __('Compare') }}</x-signal.ui.button></noscript>
        <x-signal.ui.link :href="route('deploy.builds.show', [$project, $build->id])" variant="muted">{{ __('Back to deploy #:id', ['id' => $build->id]) }}</x-signal.ui.link>
    </form>

    @if ($comparison === null)
        <x-signal.ui.empty-state icon="list" :title="__('Nothing to compare with yet')" :description="__('This repository has no other deploys. Compare becomes useful after the next one.')" />
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            <x-signal.ui.stat :label="__('Took')" :value="$seconds($build)" :description="match (true) { $comparison->durationDelta === null => __('Timing unavailable'), $comparison->durationDelta > 0 => __(':seconds s slower than #:id', ['seconds' => $comparison->durationDelta, 'id' => $baseline->id]), $comparison->durationDelta < 0 => __(':seconds s faster than #:id', ['seconds' => abs($comparison->durationDelta), 'id' => $baseline->id]), default => __('Same as #:id', ['id' => $baseline->id]) }" />
            <x-signal.ui.stat :label="__('Settings changed')" :value="$comparison->snapshotsAvailable ? (string) count($comparison->changes) : '—'" :description="$comparison->snapshotsAvailable ? __('Between the two environment snapshots') : __('One of them didn’t record its environment')" />
            <x-signal.ui.stat :label="__('Code')" :value="$baseline->revision === $build->revision ? __('Same commit') : __('Different commits')" :description="$comparison->compareUrl ? __('See the diff at your Git provider') : __('No commit range to show')" />
        </div>
        @if ($comparison->compareUrl)
            <div><x-signal.ui.button :href="$comparison->compareUrl" target="_blank" rel="noopener" variant="secondary">{{ __('View code changes') }} <x-signal.ui.icon name="external" class="size-4" /></x-signal.ui.button></div>
        @endif

        <x-signal.ui.table :caption="__('Side by side')">
            <x-slot:head><tr><th scope="col">{{ __('') }}</th><th scope="col">{{ __('#:id (baseline)', ['id' => $baseline->id]) }}</th><th scope="col">{{ __('#:id', ['id' => $build->id]) }}</th></tr></x-slot:head>
            @foreach ([
                ['Status', __(ucfirst($baseline->status)), __(ucfirst($build->status))],
                ['Commit', $baseline->shortRevision() ?? '—', $build->shortRevision() ?? '—'],
                ['Message', $baseline->commit_message ?? '—', $build->commit_message ?? '—'],
                ['Started by', ($baseline->requester?->name ?? __('A push')).' · '.__(ucfirst($baseline->trigger_source)), ($build->requester?->name ?? __('A push')).' · '.__(ucfirst($build->trigger_source))],
                ['Environment', $baseline->environment->name ?? '—', $build->environment->name ?? '—'],
                ['Started', $baseline->started_at?->toDayDateTimeString() ?? '—', $build->started_at?->toDayDateTimeString() ?? '—'],
                ['Took', $seconds($baseline), $seconds($build)],
                ['Note', $baseline->operator_note ?? '—', $build->operator_note ?? '—'],
                ['Failure', $baseline->failure_message ?? '—', $build->failure_message ?? '—'],
            ] as [$label, $before, $after])
                <tr @class(['bg-warning-soft/40' => $before !== $after])>
                    <th scope="row" class="text-left font-semibold">{{ __($label) }}</th>
                    <td class="text-muted">{{ $before }}</td>
                    <td class="text-ink">{{ $after }}</td>
                </tr>
            @endforeach
        </x-signal.ui.table>

        @if ($comparison->snapshotsAvailable)
            @if ($comparison->changes === [])
                <x-signal.ui.alert tone="info" role="status">{{ __('Both deploys ran with the same environment settings, variables and workers.') }}</x-signal.ui.alert>
            @else
                <x-signal.ui.table :caption="__('Settings that changed')">
                    <x-slot:head><tr><th scope="col">{{ __('Where') }}</th><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Change') }}</th><th scope="col">{{ __('Before') }}</th><th scope="col">{{ __('After') }}</th></tr></x-slot:head>
                    @foreach ($comparison->changes as $change)
                        <tr>
                            <td>{{ __($change->area) }}</td>
                            <td class="font-mono text-xs">{{ $change->name }}</td>
                            <td><x-signal.ui.badge :tone="$kindTones[$change->kind]">{{ __(ucfirst($change->kind)) }}</x-signal.ui.badge></td>
                            <td class="max-w-xs break-all font-mono text-xs text-muted">{{ $change->from ?? ($change->kind === 'added' ? '—' : __('hidden')) }}</td>
                            <td class="max-w-xs break-all font-mono text-xs">{{ $change->to ?? ($change->kind === 'removed' ? '—' : __('hidden')) }}</td>
                        </tr>
                    @endforeach
                </x-signal.ui.table>
                <p class="text-xs text-muted">{{ __('Variable values, the .env file and resource settings are secret, so only their names are compared.') }}</p>
            @endif
        @endif
    @endif
</x-signal.layouts.project>
