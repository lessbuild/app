<div @if ($shouldPoll) wire:poll.5s @endif>
    @php
        $repositoryEditUrl = route('builds.show', ['build' => $build, 'dialog' => 'edit-repository']);
        $websiteEditUrl = route('builds.show', ['build' => $build, 'dialog' => 'edit-website']);
        $buildPageUrl = route('builds.show', $build);
        $repositoryEditContentUrl = route('repositories.edit', ['repository' => $build->repository, 'dialog' => 'edit-repository', 'fragment' => 1, 'return_to' => $buildPageUrl]);
        $websiteEditContentUrl = route('websites.edit', ['website' => $build->repository->website, 'dialog' => 'edit-website', 'fragment' => 1, 'return_to' => $buildPageUrl]);
        $comparisonDialogId = 'build-comparison-dialog';
        $comparisonDialogKey = $previousBuild ? 'compare-build-'.$build->id.'-'.$previousBuild->id : null;
        $comparisonDialogOpen = $comparisonDialogKey !== null && request()->query('dialog') === $comparisonDialogKey;
        $comparisonDialogUrl = $comparisonDialogKey === null ? null : route('builds.show', ['build' => $build, 'dialog' => $comparisonDialogKey]);
        $comparisonContentUrl = $previousBuild === null ? null : route('builds.compare', [
            'build' => $build,
            'baseline' => $previousBuild,
            'fragment' => 'build-comparison',
        ]);
        $healthChecksDialogId = 'build-website-health-checks-dialog';
        $healthChecksDialogOpen = request()->query('dialog') === $healthChecksDialogId;
        $healthChecksDialogUrl = route('builds.show', [
            'build' => $build,
            'dialog' => $healthChecksDialogId,
        ]);
        $healthChecksContentUrl = route('websites.health-checks.index', [
            'website' => $build->repository->website,
            'fragment' => 'website-health-checks',
        ]);
        $statusTone = match ($build->status) {
            \App\Models\Build::STATUS_SUCCEEDED => 'success',
            \App\Models\Build::STATUS_FAILED, \App\Models\Build::STATUS_REJECTED => 'danger',
            \App\Models\Build::STATUS_CANCELED => 'warning',
            \App\Models\Build::STATUS_RUNNING, \App\Models\Build::STATUS_QUEUED, \App\Models\Build::STATUS_AWAITING_APPROVAL, \App\Models\Build::STATUS_TIMING_OUT => 'accent',
            default => 'neutral',
        };
    @endphp
    <x-ui.local-nav class="mt-6" :label="__('Deployment sections')">
        <a href="#build-summary" class="ui-local-nav__link">{{ __('Summary') }}</a>
        <a href="#deployment-evidence" class="ui-local-nav__link">{{ __('Evidence') }}</a>
        <a href="#deployment-timeline" class="ui-local-nav__link">{{ __('Timeline') }}</a>
        <a href="#deployment-log" class="ui-local-nav__link">{{ __('Logs') }}</a>
    </x-ui.local-nav>
    <x-ui.insights
        id="build-summary"
        class="mt-6 scroll-mt-24"
        data-build-summary
        :summary="str($build->status)->replace('_', ' ')->headline()"
        :mobile-open="true"
    >
    <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
        <div>
            <dt class="ui-stat__label">{{ __('Status') }}</dt>
            <dd class="mt-2"><x-ui.badge :tone="$statusTone">{{ str($build->status)->replace('_', ' ')->title() }}</x-ui.badge></dd>
        </div>
        <div>
            <dt class="ui-stat__label">{{ __('Triggered by') }}</dt>
            <dd class="mt-1 font-medium text-ink">{{ ucfirst($build->trigger_source) }}</dd>
        </div>
        <div>
            <dt class="ui-stat__label">{{ __('Revision') }}</dt>
            <dd class="mt-1 font-mono text-sm text-ink">
                @if ($revisionUrl = $build->repository->revisionUrl($build->revision))
                    <a href="{{ $revisionUrl }}" target="_blank" rel="noopener noreferrer" class="ui-link">{{ $build->shortRevision() }}</a>
                @else
                    {{ __('Current branch') }}
                @endif
            </dd>
        </div>
        <div>
            <dt class="ui-stat__label">{{ __('Started') }}</dt>
            <dd class="mt-1 text-ink">{{ $build->started_at?->format('Y-m-d H:i:s T') ?? __('Not started') }}</dd>
        </div>
        <div>
            <dt class="ui-stat__label">{{ __('Finished') }}</dt>
            <dd class="mt-1 text-ink">{{ $build->finished_at?->format('Y-m-d H:i:s T') ?? __('Not finished') }}</dd>
        </div>
        <div>
            <dt class="ui-stat__label">{{ __('Duration') }}</dt>
            <dd class="mt-1 text-ink">{{ $build->durationLabel() ?? __('Not recorded') }}</dd>
        </div>
        <div>
            <dt class="ui-stat__label">{{ __('Last heartbeat') }}</dt>
            <dd class="mt-1 text-ink">{{ $build->last_heartbeat_at?->format('Y-m-d H:i:s T') ?? __('Not received') }}</dd>
        </div>
    </dl>
    </x-ui.insights>

    @if ($build->status === \App\Models\Build::STATUS_FAILED && $build->failure_message)
        <aside class="ui-panel mt-6 border-l-4 border-line bg-surface-muted p-4 text-sm" style="border-left-color: var(--ui-danger)" role="alert">
            <strong class="text-ink">{{ __('Deployment failed:') }}</strong> <span class="text-muted">{{ $build->failure_message }}</span>
        </aside>
        @if ($failureGuidance)
            <section class="ui-panel mt-4 border-l-4 p-5" aria-labelledby="recovery-guidance-title">
                <p class="ui-eyebrow">{{ __('Recovery guidance') }}</p>
                <h2 id="recovery-guidance-title" class="mt-2 text-lg font-extrabold text-ink">{{ $failureGuidance['title'] }}</h2>
                <p class="mt-2 text-sm text-muted">{{ $failureGuidance['summary'] }}</p>
                <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-line bg-surface-muted p-3"><dt class="ui-eyebrow text-[0.65rem]">{{ __('Last completed step') }}</dt><dd class="mt-1 font-medium text-ink">{{ $failureGuidance['last_completed'] ?? __('None recorded') }}</dd></div>
                    <div class="rounded-lg border border-line bg-surface-muted p-3"><dt class="ui-eyebrow text-[0.65rem]">{{ __('Step to investigate') }}</dt><dd class="mt-1 font-medium text-ink">{{ $failureGuidance['failed_step'] ?? __('Finalization') }}</dd></div>
                </dl>
                <div class="mt-4 flex flex-wrap gap-3"><x-ui.button href="#deployment-log" variant="primary">{{ __('Inspect deployment log') }}</x-ui.button><x-ui.button :href="$repositoryEditUrl" data-modal-trigger="repository-edit-dialog" data-modal-content-url="{{ $repositoryEditContentUrl }}" aria-controls="repository-edit-dialog" aria-expanded="{{ $repositoryEditOpen ? 'true' : 'false' }}" variant="secondary">{{ __('Review deployment settings') }}</x-ui.button><x-ui.button :href="route('websites.show', $build->repository->website)" variant="secondary">{{ __('Inspect website health') }}</x-ui.button></div>
            </section>
        @endif
        @if ($rollbackCandidate)
            @can('rollback', $rollbackCandidate)
                <section class="ui-panel mt-4 border-l-4 border-line bg-surface-muted p-5" style="border-left-color: var(--ui-warning)" role="status">
                    <h2 class="font-black text-ink">{{ __('Restore the last known-good release') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Build #:id succeeded :time and its retained artifact can be switched live without rebuilding.', ['id' => $rollbackCandidate->id, 'time' => $rollbackCandidate->finished_at?->diffForHumans() ?? __('previously')]) }}</p>
                    <form method="POST" action="{{ route('builds.rollback', $rollbackCandidate) }}" class="mt-4">
                        @csrf
                        <x-ui.button type="submit" variant="primary" onclick="return confirm({{ Illuminate\Support\Js::from(__('Immediately restore the last known-good release?')) }})">{{ __('Restore build #:id', ['id' => $rollbackCandidate->id]) }}</x-ui.button>
                    </form>
                </section>
            @endcan
        @endif
    @endif

    <details
        id="deployment-evidence"
        class="ui-responsive-details group ui-panel mt-4 scroll-mt-24 overflow-hidden"
        open
        data-responsive-details
        data-responsive-details-mobile-open="false"
        data-build-section="evidence"
        aria-labelledby="deployment-evidence-title"
    >
        <summary class="flex cursor-pointer list-none items-start justify-between gap-4 p-5 text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-primary lg:hidden [&::-webkit-details-marker]:hidden">
            <span>
                <span class="ui-eyebrow block">{{ __('Deployment evidence') }}</span>
                <span class="mt-1 block text-lg font-extrabold">{{ __('Identity and approval context') }}</span>
                <span class="mt-1 block text-sm font-normal text-muted">{{ __('Revision, actor and approval details.') }}</span>
            </span>
            <span class="shrink-0 text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <div class="ui-responsive-details__content border-t border-line p-5 lg:border-0">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Deployment evidence') }}</p>
                <h2 id="deployment-evidence-title" class="mt-2 text-lg font-extrabold text-ink">{{ __('Identity and approval context') }}</h2>
            </div>
            <span class="text-xs text-muted">{{ __('Persisted by :app', ['app' => config('app.name')]) }}</span>
        </div>
        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Revision identity') }}</dt>
                <dd class="mt-1 break-all font-mono text-sm text-ink">{{ $build->revision ?? __('Current branch; no immutable revision recorded') }}</dd>
            </div>
            <div>
                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Requested by') }}</dt>
                <dd class="mt-1 text-sm text-ink">
                    @if ($build->requester)
                        {{ $build->requester->name }} <span class="text-muted">(#{{ $build->requester->id }})</span>
                    @else
                        {{ $build->trigger_source === \App\Models\Build::TRIGGER_WEBHOOK ? __('Source webhook') : __('No actor recorded') }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Approval') }}</dt>
                <dd class="mt-1 text-sm text-ink">
                    @if ($build->approved_at)
                        {{ __('Approved by') }} {{ $build->approver?->name ?? __('a removed account') }}{{ $build->approver ? ' (#'.$build->approver->id.')' : '' }}
                        <time datetime="{{ $build->approved_at->toIso8601String() }}" class="block text-xs text-muted">{{ $build->approved_at->format('Y-m-d H:i:s T') }}</time>
                    @elseif ($build->rejected_at)
                        {{ __('Rejected by') }} {{ $build->rejecter?->name ?? __('a removed account') }}{{ $build->rejecter ? ' (#'.$build->rejecter->id.')' : '' }}
                        <time datetime="{{ $build->rejected_at->toIso8601String() }}" class="block text-xs text-muted">{{ $build->rejected_at->format('Y-m-d H:i:s T') }}</time>
                    @elseif ($build->status === \App\Models\Build::STATUS_AWAITING_APPROVAL)
                        {{ __('Awaiting an authorized reviewer') }}
                    @else
                        {{ __('No approval recorded') }}
                    @endif
                </dd>
            </div>
            @if ($configurationOperation = $build->configurationOperation)
                <div>
                    <dt class="ui-eyebrow text-[0.65rem]">{{ __('Configuration identity') }}</dt>
                    <dd class="mt-1 text-sm text-ink">
                        {{ __('Review #:review · Application #:application · Operation #:operation', ['review' => $configurationOperation->application?->configuration_review_id ?? __('unknown'), 'application' => $configurationOperation->configuration_application_id, 'operation' => $configurationOperation->id]) }}
                        <span class="block text-xs text-muted">{{ __('Environment :environment · :kind operation', ['environment' => $configurationOperation->environment_slug, 'kind' => $configurationOperation->kind]) }}</span>
                        @if ($configurationOperation->intent_digest)
                            <span class="mt-1 block break-all font-mono text-xs text-muted">{{ __('Intent :digest', ['digest' => $configurationOperation->intent_digest]) }}</span>
                        @endif
                    </dd>
                </div>
            @endif
        </dl>
        </div>
    </details>

    @php
        $deploymentTimelineNeedsAttention = $build->statusEnum()?->isActive() === true
            || $build->status === \App\Models\Build::STATUS_FAILED;
        $deploymentStatusLabel = str($build->status)->replace('_', ' ')->headline();
    @endphp
    <details
        id="deployment-timeline"
        class="ui-responsive-details group ui-panel mt-4 scroll-mt-24 overflow-hidden"
        open
        data-responsive-details
        data-responsive-details-mobile-expanded="{{ $deploymentTimelineNeedsAttention ? 'true' : 'false' }}"
        data-build-section="timeline"
        aria-labelledby="deployment-timeline-title"
    >
        <summary class="flex cursor-pointer list-none items-start justify-between gap-4 p-5 text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-primary [&::-webkit-details-marker]:hidden">
            <span>
                <span class="ui-eyebrow block">{{ __('Deployment timeline') }}</span>
                <span id="deployment-timeline-title" class="mt-1 block text-lg font-extrabold">{{ __('Deployment timeline') }}</span>
                <span class="mt-1 block text-sm font-normal text-muted">
                    {{ __(':count milestones · :status', ['count' => count($deploymentTimeline), 'status' => $deploymentStatusLabel]) }}
                </span>
            </span>
            <span class="shrink-0 text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <div class="ui-responsive-details__content border-t border-line p-5">
        <x-deployment-timeline :entries="$deploymentTimeline" />
        </div>
    </details>

    @if($build->promotedFrom)
        <aside class="ui-panel mt-4 border-l-4 border-line bg-surface-muted p-4" style="border-left-color: var(--ui-primary)" role="status"><p class="font-bold text-ink">{{ __('Promoted release') }}</p><p class="mt-1 text-sm text-muted">{{ __('This deployment rebuilds revision :revision from :source for :target.', ['revision'=>$build->shortRevision(), 'source'=>$build->promotedFrom->environment?->name ?? __('another environment'), 'target'=>$build->environment?->name ?? __('this environment')]) }} <a href="{{ route('builds.show',$build->promotedFrom) }}" class="ui-link font-bold">{{ __('View source evidence') }}</a></p>@if($build->promotion_note)<p class="mt-2 text-sm text-muted">{{ $build->promotion_note }}</p>@endif</aside>
    @endif
    @if($build->promotions->isNotEmpty())
        <aside class="ui-panel mt-4 p-4"><p class="font-bold text-ink">{{ __('Promotion history') }}</p><div class="mt-2 flex flex-wrap gap-2">@foreach($build->promotions->sortByDesc('id') as $promotion)<a href="{{ route('builds.show',$promotion) }}" class="ui-card ui-card--interactive px-3 py-2 text-sm text-ink">{{ $promotion->environment?->name ?? __('Target') }} · {{ str($promotion->status)->replace('_',' ')->headline() }} · #{{ $promotion->id }}</a>@endforeach</div></aside>
    @endif

    <nav class="mt-4 grid gap-3 sm:grid-cols-2" aria-label="{{ __('Deployment history') }}">
        @if ($previousBuild)
            <a href="{{ route('builds.show', $previousBuild) }}" class="ui-card ui-card--interactive p-4">
                <span class="ui-eyebrow block">{{ __('Previous deployment') }}</span>
                <span class="mt-1 block font-medium text-ink">
                    {{ __('Build #:id', ['id' => $previousBuild->id]) }}
                    &middot; {{ str($previousBuild->status)->replace('_', ' ')->title() }}
                </span>
                <span class="mt-1 block text-xs text-muted">
                    {{ $previousBuild->durationLabel() ?? __('Duration not recorded') }}
                    @if ($previousBuild->shortRevision())
                        &middot; <span class="font-mono">{{ $previousBuild->shortRevision() }}</span>
                    @endif
                </span>
            </a>
        @else
            <div class="ui-card p-4 text-muted">
                <span class="ui-eyebrow block">{{ __('Previous deployment') }}</span>
                <span class="mt-1 block text-sm">{{ __('This is the first recorded deployment for this repository.') }}</span>
            </div>
        @endif

        @if ($nextBuild)
            <a href="{{ route('builds.show', $nextBuild) }}" class="ui-card ui-card--interactive p-4 text-right">
                <span class="ui-eyebrow block">{{ __('Next deployment') }}</span>
                <span class="mt-1 block font-medium text-ink">
                    {{ __('Build #:id', ['id' => $nextBuild->id]) }}
                    &middot; {{ str($nextBuild->status)->replace('_', ' ')->title() }}
                </span>
                <span class="mt-1 block text-xs text-muted">
                    {{ $nextBuild->durationLabel() ?? __('Duration not recorded') }}
                    @if ($nextBuild->shortRevision())
                        &middot; <span class="font-mono">{{ $nextBuild->shortRevision() }}</span>
                    @endif
                </span>
            </a>
        @else
            <div class="ui-card p-4 text-right text-muted">
                <span class="ui-eyebrow block">{{ __('Next deployment') }}</span>
                <span class="mt-1 block text-sm">{{ __('This is the latest recorded deployment for this repository.') }}</span>
            </div>
        @endif
    </nav>

    @if ($previousBuild)
        <div class="mt-3 flex justify-end">
            <x-ui.button
                :href="$comparisonDialogUrl"
                data-modal-trigger="{{ $comparisonDialogId }}"
                data-modal-content-url="{{ $comparisonContentUrl }}"
                data-modal-history-url="{{ $comparisonDialogUrl }}"
                aria-controls="{{ $comparisonDialogId }}"
                aria-expanded="{{ $comparisonDialogOpen ? 'true' : 'false' }}"
                variant="secondary"
            >
                {{ __('Compare with previous') }}
            </x-ui.button>
        </div>
    @endif

    @if ($build->status === \App\Models\Build::STATUS_TIMING_OUT)
        <aside class="ui-panel mt-4 border-l-4 border-line bg-surface-muted p-4" style="border-left-color: var(--ui-warning)" role="status">
            <p>{{ __('This deployment stopped reporting progress. :app is safely stopping its remote process before allowing another deployment.', ['app' => config('app.name')]) }}</p>
            @if ($build->failure_message)
                <p class="mt-1 text-sm">{{ $build->failure_message }}</p>
            @endif
        </aside>
    @endif

    @if ($build->redeployed_from_build_id)
        <p class="mt-3 text-sm text-muted">
            {{ __('Redeployment of') }}
            <a href="{{ route('builds.show', $build->redeployed_from_build_id) }}" class="ui-link font-medium">
                {{ __('Build #:id', ['id' => $build->redeployed_from_build_id]) }}
            </a>
        </p>
    @endif

    @if ($build->rolled_back_from_build_id)
        <p class="mt-3 text-sm text-muted">
            {{ __('Instant rollback to the artifact from') }}
            <a href="{{ route('builds.show', $build->rolled_back_from_build_id) }}" class="ui-link font-medium">
                {{ __('Build #:id', ['id' => $build->rolled_back_from_build_id]) }}
            </a>
        </p>
    @endif

    @if ($build->release_name)
        <div class="ui-panel mt-4 p-4">
            <p class="ui-eyebrow">{{ __('Release artifact') }}</p>
            <p class="mt-2 break-all font-mono text-sm text-ink">{{ $build->release_name }}</p>
            @if ($build->activated_at)
                <p class="mt-1 text-xs text-muted">{{ __('Activated :time', ['time' => $build->activated_at->diffForHumans()]) }}</p>
            @endif
        </div>
    @endif

    @if ($build->commit_message)
        <div class="ui-panel mt-4 p-4">
            <p class="ui-eyebrow">{{ __('Commit message') }}</p>
            <p class="mt-2 whitespace-pre-wrap break-words text-sm text-ink">{{ $build->commit_message }}</p>
        </div>
    @endif

    @if ($build->risk_assessment)
        <section class="ui-panel mt-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><p class="ui-eyebrow">{{ __('Deployment preflight') }}</p><h2 class="mt-2 font-extrabold text-ink">{{ __('Risk: :level', ['level' => str($build->risk_assessment['level'] ?? 'unknown')->headline()]) }}</h2></div><x-ui.badge tone="accent">{{ $build->risk_assessment['score'] ?? 0 }}/100</x-ui.badge></div>
            <ul class="mt-4 grid gap-2 sm:grid-cols-2">@foreach($build->risk_assessment['checks'] ?? [] as $check)<li class="flex gap-2 rounded-lg border border-line bg-surface-muted p-3 text-sm"><span class="font-black {{ match ($check['status']) { 'passed' => 'text-success', 'warning' => 'text-warning', default => 'text-danger' } }}">{{ $check['status'] === 'passed' ? '✓' : '!' }}</span><span><strong class="block text-ink">{{ $check['name'] }}</strong><span class="text-xs text-muted">{{ $check['detail'] }}</span></span></li>@endforeach</ul>
        </section>
    @endif

    @if ($build->automatic_rollback_build_id)
        <aside class="ui-panel mt-4 border-l-4 border-line bg-surface-muted p-4 text-sm" style="border-left-color: var(--ui-warning)" role="status">{{ __('Automatic recovery was queued as') }} <a class="ui-link font-bold" href="{{ route('builds.show', $build->automatic_rollback_build_id) }}">{{ __('build #:id', ['id' => $build->automatic_rollback_build_id]) }}</a>.</aside>
    @endif

    @if ($build->status === \App\Models\Build::STATUS_AWAITING_APPROVAL)
        <section class="ui-panel mt-4 border-l-4 border-line bg-surface-muted p-5" style="border-left-color: var(--ui-warning)" role="status">
            <h2 class="font-semibold text-ink">{{ __('Production approval required') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('This protected environment will not receive traffic until an owner or administrator approves the deployment.') }}</p>
            @can('approve', $build)
                <form method="POST" class="mt-4" id="deployment-approval-form">
                    @csrf
                    <label class="ui-label block text-sm font-medium">
                        {{ __('Decision note (optional)') }}
                        <textarea name="approval_note" rows="3" maxlength="2000" class="ui-input mt-2 min-h-24 w-full" placeholder="{{ __('Change ticket, reviewer context, or rejection reason') }}">{{ old('approval_note') }}</textarea>
                    </label>
                    <x-forms.errors name="approval_note" bag="approval" />
                    <div class="mt-3 flex flex-wrap gap-3">
                        <x-ui.button type="submit" variant="primary" formaction="{{ route('builds.approve', $build) }}">{{ __('Approve and deploy') }}</x-ui.button>
                        <x-ui.button type="submit" variant="secondary" formaction="{{ route('builds.reject', $build) }}" onclick="return confirm({{ Illuminate\Support\Js::from(__('Reject this deployment request?')) }})">{{ __('Reject') }}</x-ui.button>
                    </div>
                </form>
            @endcan
        </section>
    @elseif ($build->status === \App\Models\Build::STATUS_REJECTED)
        <aside class="ui-panel mt-4 border-l-4 border-line bg-surface-muted p-4" style="border-left-color: var(--ui-danger)" role="alert">
            <strong class="text-ink">{{ __('Deployment rejected.') }}</strong>
            @if ($build->approval_note)
                <span>{{ $build->approval_note }}</span>
            @endif
        </aside>
    @elseif ($build->approved_at)
        <aside class="ui-panel mt-4 border-l-4 border-line bg-surface-muted p-4" style="border-left-color: var(--ui-success)" role="status">
            {{ __('Approved :time.', ['time' => $build->approved_at->diffForHumans()]) }}
            @if ($build->approval_note)
                <span>{{ $build->approval_note }}</span>
            @endif
        </aside>
    @endif

    @php
        $noteDialogId = 'build-note-dialog';
        $noteDialogOpen = request()->query('dialog') === 'operator-note'
            || $errors->getBag('buildNote')->any();
        $noteDialogUrl = route('builds.show', ['build' => $build, 'dialog' => 'operator-note']);
    @endphp
    <section class="ui-panel mt-4 p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="ui-eyebrow">{{ __('Operator context') }}</p>
                <h2 class="mt-2 font-extrabold text-ink">{{ __('Operator note') }}</h2>
                <p class="mt-1 text-sm text-muted">
                    {{ __('Record an incident ticket, rollback reason, approval, or handoff context. Notes are searchable and included in build exports, so do not store secrets.') }}
                </p>
            </div>
            <x-ui.button
                href="{{ $noteDialogUrl }}"
                data-modal-trigger="{{ $noteDialogId }}"
                aria-controls="{{ $noteDialogId }}"
                aria-expanded="{{ $noteDialogOpen ? 'true' : 'false' }}"
                variant="secondary"
            >
                {{ $build->operator_note ? __('Edit operator note') : __('Add operator note') }}
            </x-ui.button>
        </div>

        @if ($build->operator_note)
            <div class="mt-4 rounded-lg border border-line bg-surface-muted p-3">
                <p class="whitespace-pre-wrap text-sm text-ink">{{ $build->operator_note }}</p>
            </div>
        @else
            <p class="mt-4 text-sm text-muted">{{ __('No operator note has been recorded.') }}</p>
        @endif

        <x-scenes.builds.operator-note-dialog
            :build="$build"
            :open="$noteDialogOpen"
        />
    </section>

    @if (in_array($build->status, [\App\Models\Build::STATUS_QUEUED, \App\Models\Build::STATUS_AWAITING_APPROVAL], true) || ($build->status === \App\Models\Build::STATUS_RUNNING && $build->remote_process_id && $build->remote_process_path))
        <form method="POST" action="{{ route('builds.cancel', $build) }}" class="mt-4">
            @csrf
            <x-ui.button
                type="submit"
                variant="primary"
                onclick="return confirm({{ Illuminate\Support\Js::from($build->status === \App\Models\Build::STATUS_QUEUED
                    ? __('Remove this deployment from the queue?')
                    : __('Stop this deployment on the remote server?')) }})"
            >
                {{ $build->status === \App\Models\Build::STATUS_QUEUED ? __('Cancel queued deployment') : ($build->status === \App\Models\Build::STATUS_AWAITING_APPROVAL ? __('Cancel deployment request') : __('Cancel deployment')) }}
            </x-ui.button>
        </form>
    @endif

    @if (in_array($build->status, \App\Models\Build::TERMINAL_STATUSES, true))
        <form method="POST" action="{{ route('builds.redeploy', $build) }}" class="mt-4">
            @csrf
            <x-ui.button
                type="submit"
                variant="primary"
                onclick="return confirm({{ Illuminate\Support\Js::from($build->revision
                    ? __('Redeploy this exact revision?')
                    : __('Redeploy the repository branch?')) }})"
            >
                {{ $build->revision ? __('Redeploy this revision') : __('Retry deployment') }}
            </x-ui.button>
        </form>
    @endif

    @if ($build->status === \App\Models\Build::STATUS_SUCCEEDED && $build->release_name && $build->release_path && $build->trigger_source !== \App\Models\Build::TRIGGER_ROLLBACK)
        @can('rollback', $build)
            <form method="POST" action="{{ route('builds.rollback', $build) }}" class="mt-4">
                @csrf
                <x-ui.button type="submit" variant="secondary" onclick="return confirm({{ Illuminate\Support\Js::from(__('Immediately switch traffic back to this retained release?')) }})">
                    {{ __('Instant rollback to this release') }}
                </x-ui.button>
            </form>
        @endcan
    @endif

    @if ($build->status === \App\Models\Build::STATUS_SUCCEEDED)
        @if ($deploymentObservation)
            <section class="ui-panel mt-4 p-5" aria-labelledby="deployment-observation-title">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="ui-eyebrow">{{ __('Post-deployment observation') }}</p>
                        <h2 id="deployment-observation-title" class="mt-2 text-lg font-extrabold text-ink">{{ __('Revision-linked verification') }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ __(':app checks this deployment’s captured health target during a bounded window. This result is separate from continuous website health monitoring.', ['app' => config('app.name')]) }}</p>
                    </div>
                    <x-ui.badge :tone="match ($deploymentObservation->statusEnum()?->value) {
                        'healthy' => 'success',
                        'failed' => 'danger',
                        'pending', 'observing' => 'accent',
                        'expired', 'superseded' => 'warning',
                        default => 'neutral',
                    }">{{ str($deploymentObservation->status)->replace('_', ' ')->headline() }}</x-ui.badge>
                </div>
                <dl class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('Observation window') }}</dt>
                        <dd class="mt-1 text-sm text-ink">{{ trans_choice(':minutes minute|:minutes minutes', $deploymentObservation->duration_minutes, ['minutes' => $deploymentObservation->duration_minutes]) }}</dd>
                    </div>
                    <div>
                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('Successful checks') }}</dt>
                        <dd class="mt-1 text-sm text-ink">{{ $deploymentObservation->successful_checks }}</dd>
                    </div>
                    <div>
                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('Last HTTP status') }}</dt>
                        <dd class="mt-1 text-sm text-ink">{{ $deploymentObservation->last_http_status ?? __('Not checked yet') }}</dd>
                    </div>
                    <div>
                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('Last checked') }}</dt>
                        <dd class="mt-1 text-sm text-ink">{{ $deploymentObservation->last_checked_at?->format('Y-m-d H:i:s T') ?? __('Not checked yet') }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-sm text-muted">
                    @switch($deploymentObservation->statusEnum()?->value)
                        @case('pending')
                            {{ __('The first post-deployment check is waiting to run.') }}
                            @break
                        @case('observing')
                            {{ __('The observation is still running. The next check is scheduled automatically.') }}
                            @break
                        @case('healthy')
                            {{ __('The deployment completed its observation window with successful checks.') }}
                            @break
                        @case('failed')
                            {{ __('The deployment did not complete post-deployment verification. Review the deployment log and website health history for details.') }}
                            @break
                        @case('expired')
                            {{ __('The observation window expired before verification completed.') }}
                            @break
                        @case('superseded')
                            {{ __('A newer successful deployment replaced this observation.') }}
                            @break
                        @default
                            {{ __('This observation uses a legacy state that is not displayed in detail.') }}
                    @endswitch
                </p>
            </section>
        @endif
        <section class="ui-panel mt-4 p-5" aria-labelledby="deployment-health-title">
            <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="ui-eyebrow">{{ __('Post-deployment verification') }}</p><h2 id="deployment-health-title" class="mt-2 text-lg font-extrabold text-ink">{{ __('Application health') }}</h2><p class="mt-1 text-sm text-muted">{{ $website->health_check_enabled ? __('The deployment health path is :path. Current monitor state: :state.', ['path' => $website->health_check_path, 'state' => str($website->health_status)->headline()]) : __('Continuous health monitoring is disabled. Enable it to detect regressions after deployment.') }}</p></div><x-ui.badge :tone="$website->health_status === 'healthy' ? 'success' : ($website->health_status === 'unhealthy' ? 'danger' : 'neutral')">{{ $website->health_check_enabled ? str($website->health_status)->headline() : __('Disabled') }}</x-ui.badge></div>
            <div class="mt-4 flex flex-wrap gap-3"><x-ui.button href="https://{{ $website->url }}" variant="primary" class="ui-btn-sm" target="_blank" rel="noopener noreferrer">{{ __('Open live website') }}</x-ui.button><x-ui.button :href="route('websites.show', $website).'#health-history-heading'" data-modal-trigger="{{ $healthChecksDialogId }}" data-modal-content-url="{{ $healthChecksContentUrl }}" data-modal-history-url="{{ $healthChecksDialogUrl }}" aria-controls="{{ $healthChecksDialogId }}" aria-expanded="{{ $healthChecksDialogOpen ? 'true' : 'false' }}" variant="secondary" class="ui-btn-sm">{{ __('View health history') }}</x-ui.button>@if($website->health_check_enabled)<form method="POST" action="{{ route('websites.health.check', $website) }}">@csrf<x-ui.button type="submit" variant="secondary" class="ui-btn-sm">{{ __('Run health check now') }}</x-ui.button></form>@else<x-ui.button :href="$websiteEditUrl" data-modal-trigger="website-edit-dialog" data-modal-content-url="{{ $websiteEditContentUrl }}" aria-controls="website-edit-dialog" aria-expanded="{{ $websiteEditOpen ? 'true' : 'false' }}" variant="secondary" class="ui-btn-sm">{{ __('Enable health monitoring') }}</x-ui.button>@endif</div>
        </section>
    @endif

    @if ($build->status === \App\Models\Build::STATUS_CANCELED)
        <aside class="ui-panel mt-6 border-l-4 border-line bg-surface-muted p-4 text-sm" style="border-left-color: var(--ui-warning)" role="status">
            {{ __('This deployment was canceled before it completed.') }}
        </aside>
    @endif

    @php($deploymentLogNeedsAttention = $build->statusEnum()?->isActive() === true)
    <details
        id="deployment-log"
        class="group ui-panel mt-8 scroll-mt-24 overflow-hidden"
        data-build-section="logs"
        @if ($deploymentLogNeedsAttention) open @endif
    >
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-ink [&::-webkit-details-marker]:hidden">
            <span>
                <span class="ui-eyebrow block">{{ __('Logs') }}</span>
                <span class="mt-1 block text-2xl">{{ __('Deployment log') }}</span>
                <span class="mt-1 block text-sm font-normal text-muted">
                    @if ($deploymentLog)
                        {{ __('Updated :time · bounded output', ['time' => $deploymentLog->updated_at->diffForHumans()]) }}
                    @elseif ($shouldPoll)
                        {{ __('Waiting for deployment output…') }}
                    @else
                        {{ __('No remote deployment output was received.') }}
                    @endif
                </span>
            </span>
            <span class="text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <section class="border-t border-line p-5 pt-4" aria-labelledby="deployment-log-title">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 id="deployment-log-title" class="sr-only">{{ __('Deployment log') }}</h2>
                @if ($deploymentLog)
                    <div class="ml-auto">
                        <a href="{{ route('builds.log.download', $build) }}" class="ui-link text-sm font-medium">
                            {{ __('Download log') }}
                        </a>
                    </div>
                @endif
            </div>

            @if ($deploymentLog)
                <pre class="max-h-[36rem] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-950 p-5 font-mono text-xs leading-5 text-slate-100">{{ $deploymentLog->log }}</pre>
            @elseif ($shouldPoll)
                <div class="ui-card p-6 text-center">
                    <p class="font-medium text-ink">{{ __('Waiting for deployment output…') }}</p>
                    <p class="mt-1 text-sm text-muted">{{ __('This view updates automatically while the deployment runs.') }}</p>
                </div>
            @else
                <x-lists.empty
                    :title="__('No deployment log yet')"
                    :description="__('No remote deployment output was received.')"
                />
            @endif
        </section>
    </details>

    <x-scenes.repositories.edit-dialog
        :repository="$build->repository"
        :providers="$repositoryProviders"
        :websites="$repositoryWebsites"
        :open="$repositoryEditOpen"
        :cancel-url="route('builds.show', $build)"
        :content-url="$repositoryEditContentUrl"
    />

    <x-scenes.websites.edit-dialog
        :website="$website"
        :servers="$websiteServers"
        :open="$websiteEditOpen"
        :cancel-url="route('builds.show', $build)"
        :content-url="$websiteEditContentUrl"
    />

    <x-dialogs.modal
        id="{{ $comparisonDialogId }}"
        :title="__('Compare deployments')"
        :description="__('Review recorded outcomes without leaving this deployment.')"
        :open="$comparisonDialogOpen"
        body-class="p-0"
        wire:ignore
    >
        <div data-modal-content>
            <p class="p-5 text-sm text-muted">{{ __('Loading deployment comparison…') }}</p>
        </div>
    </x-dialogs.modal>

    <x-dialogs.modal
        id="{{ $healthChecksDialogId }}"
        :title="__('Health check history')"
        :description="__('Review retained website observations without leaving this deployment.')"
        :open="$healthChecksDialogOpen"
        body-class="p-0"
        wire:ignore
    >
        <div data-modal-content>
            <p class="p-5 text-sm text-muted">{{ __('Loading health history…') }}</p>
        </div>
    </x-dialogs.modal>
</div>
