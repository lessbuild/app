@php
    $historyUrl = route('builds.index', array_filter($filters, fn ($value) => $value !== null));
@endphp

<div class="space-y-5 p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="ui-eyebrow text-[0.65rem]">{{ __('Release operations') }}</p>
            <h3 class="mt-1 text-xl font-extrabold text-ink">{{ __('Recent deployments') }}</h3>
            <p class="mt-1 text-sm text-muted">{{ __('Review revision, status and timing without leaving this page.') }}</p>
        </div>
        <x-signal.ui.button :href="$historyUrl" variant="secondary">{{ __('Open full history') }}</x-signal.ui.button>
    </div>

    <x-signal.ui.insights
        id="deployment-history-dialog-insights"
        :summary="trans_choice(':count matching deployment|:count matching deployments', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-3 sm:grid-cols-3">
            <x-signal.ui.stat class="ui-card" :label="__('Deployments')" :value="$metrics['total']" :description="__('Matching workspace history.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Active')" :value="$metrics['active']" :description="__('Queued or running work.')" />
            <x-signal.ui.stat class="ui-card" :label="__('Observed success')" :value="$metrics['success_rate'] !== null ? $metrics['success_rate'].'%' : __('Not available')" :description="__('Succeeded versus failed outcomes.')" />
        </dl>
    </x-signal.ui.insights>

    @if ($builds->isEmpty())
        <x-signal.ui.empty-state
            :title="array_filter($filters, fn ($value) => $value !== null) ? __('No deployments match these filters.') : __('No deployments have been recorded yet.')"
            :description="__('Deployment history will appear here after the first deployment request.')"
        />
    @else
        <ol class="relative space-y-3 border-l border-line pl-4" aria-label="{{ __('Deployment timeline') }}">
            @foreach ($builds as $build)
                <li data-build-card class="ui-card relative p-4">
                    <span class="absolute -left-[1.35rem] top-5 h-3 w-3 rounded-full border-2 border-surface" style="background-color: var(--ui-primary)" aria-hidden="true"></span>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-ink">{{ $build->repository->name }}</p>
                            @if ($build->commit_message)
                                <p class="mt-1 line-clamp-2 text-xs text-muted">{{ $build->commit_message }}</p>
                            @endif
                            <p class="mt-1 text-xs text-muted">
                                {{ ucfirst($build->trigger_source) }} · {{ $build->created_at?->diffForHumans() ?? __('Date unavailable') }}
                            </p>
                        </div>
                        <x-signal.ui.badge :tone="match ($build->status) {
                            \App\Modules\Deployer\Models\Build::STATUS_SUCCEEDED => 'success',
                            \App\Modules\Deployer\Models\Build::STATUS_FAILED => 'danger',
                            \App\Modules\Deployer\Models\Build::STATUS_CANCELED, \App\Modules\Deployer\Models\Build::STATUS_REJECTED => 'warning',
                            \App\Modules\Deployer\Models\Build::STATUS_RUNNING, \App\Modules\Deployer\Models\Build::STATUS_QUEUED, \App\Modules\Deployer\Models\Build::STATUS_TIMING_OUT => 'accent',
                            default => 'neutral',
                        }">{{ str($build->status)->replace('_', ' ') }}</x-signal.ui.badge>
                    </div>
                    <dl class="mt-3 grid gap-3 text-xs sm:grid-cols-3">
                        <div>
                            <dt class="ui-eyebrow text-[0.62rem]">{{ __('Revision') }}</dt>
                            <dd class="mt-1 break-all font-mono text-ink">{{ $build->revision ? $build->shortRevision() : __('Current branch') }}</dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.62rem]">{{ __('Finished') }}</dt>
                            <dd class="mt-1 text-ink">{{ $build->finished_at?->diffForHumans() ?? __('Not finished') }}</dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.62rem]">{{ __('Duration') }}</dt>
                            <dd class="mt-1 text-ink">{{ $build->durationLabel() ?? __('Not recorded') }}</dd>
                        </div>
                    </dl>
                    <div class="mt-3">
                        <x-signal.ui.button :href="route('builds.show', $build)" variant="ghost">{{ __('View deployment') }}</x-signal.ui.button>
                    </div>
                </li>
            @endforeach
        </ol>
        @if ($builds->hasMorePages())
            <div class="pt-1">{{ $builds->links() }}</div>
        @endif
    @endif
</div>
