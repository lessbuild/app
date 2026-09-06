<div @if ($shouldPoll) wire:poll.5s @endif>
    <dl class="mt-6 grid gap-4 rounded-lg border border-primary bg-primary p-5 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
        <div>
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</dt>
            <dd class="mt-1 font-medium text-primary">{{ str($build->status)->replace('_', ' ')->title() }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Triggered by') }}</dt>
            <dd class="mt-1 font-medium text-primary">{{ ucfirst($build->trigger_source) }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Revision') }}</dt>
            <dd class="mt-1 font-mono text-sm text-primary">
                @if ($revisionUrl = $build->repository->revisionUrl($build->revision))
                    <a href="{{ $revisionUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ $build->shortRevision() }}</a>
                @else
                    {{ __('Current branch') }}
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Started') }}</dt>
            <dd class="mt-1 text-primary">{{ $build->started_at?->format('Y-m-d H:i:s T') ?? __('Not started') }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Finished') }}</dt>
            <dd class="mt-1 text-primary">{{ $build->finished_at?->format('Y-m-d H:i:s T') ?? __('Not finished') }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Duration') }}</dt>
            <dd class="mt-1 text-primary">{{ $build->durationLabel() ?? __('Not recorded') }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Last heartbeat') }}</dt>
            <dd class="mt-1 text-primary">{{ $build->last_heartbeat_at?->format('Y-m-d H:i:s T') ?? __('Not received') }}</dd>
        </div>
    </dl>

    @if($build->promotedFrom)
        <aside class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 text-blue-950"><p class="font-bold">{{ __('Promoted release') }}</p><p class="mt-1 text-sm">{{ __('This deployment rebuilds revision :revision from :source for :target.', ['revision'=>$build->shortRevision(), 'source'=>$build->promotedFrom->environment?->name ?? __('another environment'), 'target'=>$build->environment?->name ?? __('this environment')]) }} <a href="{{ route('builds.show',$build->promotedFrom) }}" class="font-bold underline">{{ __('View source evidence') }}</a></p>@if($build->promotion_note)<p class="mt-2 text-sm">{{ $build->promotion_note }}</p>@endif</aside>
    @endif
    @if($build->promotions->isNotEmpty())
        <aside class="mt-4 rounded-lg border border-primary bg-primary p-4"><p class="font-bold text-primary">{{ __('Promotion history') }}</p><div class="mt-2 flex flex-wrap gap-2">@foreach($build->promotions->sortByDesc('id') as $promotion)<a href="{{ route('builds.show',$promotion) }}" class="rounded-lg bg-secondary px-3 py-2 text-sm text-primary">{{ $promotion->environment?->name ?? __('Target') }} · {{ str($promotion->status)->replace('_',' ')->headline() }} · #{{ $promotion->id }}</a>@endforeach</div></aside>
    @endif

    @php
        $progressModel = clone $build;
        $progressModel->setAttribute('provisioning_status', match ($build->status) {
            \App\Models\Build::STATUS_SUCCEEDED => 'active',
            \App\Models\Build::STATUS_FAILED => 'failed',
            \App\Models\Build::STATUS_CANCELED, \App\Models\Build::STATUS_REJECTED => 'canceled',
            default => $build->status,
        });
        $progressModel->setAttribute('provisioning_error', $build->failure_message);
    @endphp
    @include('livewire.setup', ['model' => $progressModel, 'processes' => $processes, 'poll' => false, 'heading' => __('Deployment progress')])

    <nav class="mt-4 grid gap-3 sm:grid-cols-2" aria-label="{{ __('Deployment history') }}">
        @if ($previousBuild)
            <a href="{{ route('builds.show', $previousBuild) }}" class="rounded-lg border border-primary bg-primary p-4 hover:bg-secondary">
                <span class="block text-xs font-semibold uppercase text-secondary">{{ __('Previous deployment') }}</span>
                <span class="mt-1 block font-medium text-primary">
                    {{ __('Build #:id', ['id' => $previousBuild->id]) }}
                    &middot; {{ str($previousBuild->status)->replace('_', ' ')->title() }}
                </span>
                <span class="mt-1 block text-xs text-secondary">
                    {{ $previousBuild->durationLabel() ?? __('Duration not recorded') }}
                    @if ($previousBuild->shortRevision())
                        &middot; <span class="font-mono">{{ $previousBuild->shortRevision() }}</span>
                    @endif
                </span>
            </a>
        @else
            <div class="rounded-lg border border-primary bg-primary p-4 text-secondary">
                <span class="block text-xs font-semibold uppercase">{{ __('Previous deployment') }}</span>
                <span class="mt-1 block text-sm">{{ __('This is the first recorded deployment for this repository.') }}</span>
            </div>
        @endif

        @if ($nextBuild)
            <a href="{{ route('builds.show', $nextBuild) }}" class="rounded-lg border border-primary bg-primary p-4 text-right hover:bg-secondary">
                <span class="block text-xs font-semibold uppercase text-secondary">{{ __('Next deployment') }}</span>
                <span class="mt-1 block font-medium text-primary">
                    {{ __('Build #:id', ['id' => $nextBuild->id]) }}
                    &middot; {{ str($nextBuild->status)->replace('_', ' ')->title() }}
                </span>
                <span class="mt-1 block text-xs text-secondary">
                    {{ $nextBuild->durationLabel() ?? __('Duration not recorded') }}
                    @if ($nextBuild->shortRevision())
                        &middot; <span class="font-mono">{{ $nextBuild->shortRevision() }}</span>
                    @endif
                </span>
            </a>
        @else
            <div class="rounded-lg border border-primary bg-primary p-4 text-right text-secondary">
                <span class="block text-xs font-semibold uppercase">{{ __('Next deployment') }}</span>
                <span class="mt-1 block text-sm">{{ __('This is the latest recorded deployment for this repository.') }}</span>
            </div>
        @endif
    </nav>

    @if ($previousBuild)
        <div class="mt-3 flex justify-end">
            <a href="{{ route('builds.compare', ['build' => $build, 'baseline' => $previousBuild]) }}" class="button primary">
                {{ __('Compare with previous') }}
            </a>
        </div>
    @endif

    @if ($build->status === \App\Models\Build::STATUS_TIMING_OUT)
        <div class="mt-4 rounded border border-amber-300 bg-amber-50 p-4 text-amber-800">
            <p>{{ __('This deployment stopped reporting progress. BuildPusher is safely stopping its remote process before allowing another deployment.') }}</p>
            @if ($build->failure_message)
                <p class="mt-1 text-sm">{{ $build->failure_message }}</p>
            @endif
        </div>
    @endif

    @if ($build->redeployed_from_build_id)
        <p class="mt-3 text-sm text-secondary">
            {{ __('Redeployment of') }}
            <a href="{{ route('builds.show', $build->redeployed_from_build_id) }}" class="font-medium text-primary hover:underline">
                {{ __('Build #:id', ['id' => $build->redeployed_from_build_id]) }}
            </a>
        </p>
    @endif

    @if ($build->rolled_back_from_build_id)
        <p class="mt-3 text-sm text-secondary">
            {{ __('Instant rollback to the artifact from') }}
            <a href="{{ route('builds.show', $build->rolled_back_from_build_id) }}" class="font-medium text-primary hover:underline">
                {{ __('Build #:id', ['id' => $build->rolled_back_from_build_id]) }}
            </a>
        </p>
    @endif

    @if ($build->release_name)
        <div class="mt-4 rounded-lg border border-primary bg-primary p-4">
            <p class="text-xs font-semibold uppercase text-secondary">{{ __('Release artifact') }}</p>
            <p class="mt-1 break-all font-mono text-sm text-primary">{{ $build->release_name }}</p>
            @if ($build->activated_at)
                <p class="mt-1 text-xs text-secondary">{{ __('Activated :time', ['time' => $build->activated_at->diffForHumans()]) }}</p>
            @endif
        </div>
    @endif

    @if ($build->commit_message)
        <div class="mt-4 rounded-lg border border-primary bg-primary p-4">
            <p class="text-xs font-semibold uppercase text-secondary">{{ __('Commit message') }}</p>
            <p class="mt-1 whitespace-pre-wrap break-words text-sm text-primary">{{ $build->commit_message }}</p>
        </div>
    @endif

    @if ($build->risk_assessment)
        <section class="mt-4 rounded-lg border border-primary bg-primary p-4">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-xs font-semibold uppercase text-secondary">{{ __('Deployment preflight') }}</p><h2 class="mt-1 font-bold text-primary">{{ __('Risk: :level', ['level' => str($build->risk_assessment['level'] ?? 'unknown')->headline()]) }}</h2></div><span class="rounded-full bg-secondary px-3 py-1 text-sm font-black text-primary">{{ $build->risk_assessment['score'] ?? 0 }}/100</span></div>
            <ul class="mt-4 grid gap-2 sm:grid-cols-2">@foreach($build->risk_assessment['checks'] ?? [] as $check)<li class="flex gap-2 rounded-lg bg-secondary p-3 text-sm"><span class="font-black {{ $check['status'] === 'passed' ? 'text-green-600' : ($check['status'] === 'warning' ? 'text-amber-600' : 'text-red-600') }}">{{ $check['status'] === 'passed' ? '✓' : '!' }}</span><span><strong class="block text-primary">{{ $check['name'] }}</strong><span class="text-xs text-secondary">{{ $check['detail'] }}</span></span></li>@endforeach</ul>
        </section>
    @endif

    @if ($build->automatic_rollback_build_id)
        <div class="mt-4 rounded border border-amber-300 bg-amber-50 p-4 text-amber-950">{{ __('Automatic recovery was queued as') }} <a class="font-bold underline" href="{{ route('builds.show', $build->automatic_rollback_build_id) }}">{{ __('build #:id', ['id' => $build->automatic_rollback_build_id]) }}</a>.</div>
    @endif

    @if ($build->status === \App\Models\Build::STATUS_AWAITING_APPROVAL)
        <section class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-5 text-amber-950">
            <h2 class="font-semibold">{{ __('Production approval required') }}</h2>
            <p class="mt-1 text-sm">{{ __('This protected environment will not receive traffic until an owner or administrator approves the deployment.') }}</p>
            @can('approve', $build)
                <form method="POST" class="mt-4" id="deployment-approval-form">
                    @csrf
                    <label class="block text-sm font-medium">
                        {{ __('Decision note (optional)') }}
                        <textarea name="approval_note" rows="3" maxlength="2000" class="input secondary mt-2 w-full rounded" placeholder="{{ __('Change ticket, reviewer context, or rejection reason') }}">{{ old('approval_note') }}</textarea>
                    </label>
                    <x-forms.errors name="approval_note" bag="approval" />
                    <div class="mt-3 flex flex-wrap gap-3">
                        <button type="submit" formaction="{{ route('builds.approve', $build) }}" class="button primary">{{ __('Approve and deploy') }}</button>
                        <button type="submit" formaction="{{ route('builds.reject', $build) }}" class="button secondary" onclick="return confirm({{ Illuminate\Support\Js::from(__('Reject this deployment request?')) }})">{{ __('Reject') }}</button>
                    </div>
                </form>
            @endcan
        </section>
    @elseif ($build->status === \App\Models\Build::STATUS_REJECTED)
        <div class="mt-4 rounded border border-red-300 bg-red-50 p-4 text-red-800">
            <strong>{{ __('Deployment rejected.') }}</strong>
            @if ($build->approval_note)
                <span>{{ $build->approval_note }}</span>
            @endif
        </div>
    @elseif ($build->approved_at)
        <div class="mt-4 rounded border border-green-300 bg-green-50 p-4 text-green-800">
            {{ __('Approved :time.', ['time' => $build->approved_at->diffForHumans()]) }}
            @if ($build->approval_note)
                <span>{{ $build->approval_note }}</span>
            @endif
        </div>
    @endif

    <section class="mt-4 rounded-lg border border-primary bg-primary p-4">
        <h2 class="font-semibold text-primary">{{ __('Operator note') }}</h2>
        <p class="mt-1 text-sm text-secondary">
            {{ __('Record an incident ticket, rollback reason, approval, or handoff context. Notes are searchable and included in build exports, so do not store secrets.') }}
        </p>
        <form method="POST" action="{{ route('builds.note.update', $build) }}" class="mt-4">
            @csrf
            @method('PATCH')
            <label class="block">
                <span class="sr-only">{{ __('Operator note') }}</span>
                <textarea
                    name="operator_note"
                    rows="4"
                    maxlength="2000"
                    class="input secondary w-full rounded"
                    placeholder="{{ __('Example: Approved rollback for incident INC-1042.') }}"
                >{{ old('operator_note', $build->operator_note) }}</textarea>
            </label>
            <x-forms.errors name="operator_note" bag="buildNote" />
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-secondary">{{ __('Remove all text and save to clear the note.') }}</p>
                <button type="submit" class="button primary">{{ __('Save note') }}</button>
            </div>
        </form>
    </section>

    @if (in_array($build->status, [\App\Models\Build::STATUS_QUEUED, \App\Models\Build::STATUS_AWAITING_APPROVAL], true) || ($build->status === \App\Models\Build::STATUS_RUNNING && $build->remote_process_id && $build->remote_process_path))
        <form method="POST" action="{{ route('builds.cancel', $build) }}" class="mt-4">
            @csrf
            <button
                type="submit"
                class="button primary"
                onclick="return confirm({{ Illuminate\Support\Js::from($build->status === \App\Models\Build::STATUS_QUEUED
                    ? __('Remove this deployment from the queue?')
                    : __('Stop this deployment on the remote server?')) }})"
            >
                {{ $build->status === \App\Models\Build::STATUS_QUEUED ? __('Cancel queued deployment') : ($build->status === \App\Models\Build::STATUS_AWAITING_APPROVAL ? __('Cancel deployment request') : __('Cancel deployment')) }}
            </button>
        </form>
    @endif

    @if (in_array($build->status, \App\Models\Build::TERMINAL_STATUSES, true))
        <form method="POST" action="{{ route('builds.redeploy', $build) }}" class="mt-4">
            @csrf
            <button
                type="submit"
                class="button primary"
                onclick="return confirm({{ Illuminate\Support\Js::from($build->revision
                    ? __('Redeploy this exact revision?')
                    : __('Redeploy the repository branch?')) }})"
            >
                {{ $build->revision ? __('Redeploy this revision') : __('Retry deployment') }}
            </button>
        </form>
    @endif

    @if ($build->status === \App\Models\Build::STATUS_SUCCEEDED && $build->release_name && $build->release_path && $build->trigger_source !== \App\Models\Build::TRIGGER_ROLLBACK)
        @can('rollback', $build)
            <form method="POST" action="{{ route('builds.rollback', $build) }}" class="mt-4">
                @csrf
                <button type="submit" class="button secondary" onclick="return confirm({{ Illuminate\Support\Js::from(__('Immediately switch traffic back to this retained release?')) }})">
                    {{ __('Instant rollback to this release') }}
                </button>
            </form>
        @endcan
    @endif

    @if ($build->status === \App\Models\Build::STATUS_FAILED && $build->failure_message)
        <div class="mt-6 rounded border border-red-300 bg-red-50 p-4 text-red-800">
            <strong>{{ __('Deployment failed:') }}</strong> {{ $build->failure_message }}
        </div>
        @if ($failureGuidance)
            <section class="mt-4 rounded-lg border border-red-300 bg-primary p-5" aria-labelledby="recovery-guidance-title">
                <p class="text-xs font-bold uppercase tracking-widest text-red-600">{{ __('Recovery guidance') }}</p>
                <h2 id="recovery-guidance-title" class="mt-1 text-lg font-black text-primary">{{ $failureGuidance['title'] }}</h2>
                <p class="mt-2 text-sm text-secondary">{{ $failureGuidance['summary'] }}</p>
                <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg bg-secondary p-3"><dt class="text-xs font-semibold uppercase text-secondary">{{ __('Last completed step') }}</dt><dd class="mt-1 font-medium text-primary">{{ $failureGuidance['last_completed'] ?? __('None recorded') }}</dd></div>
                    <div class="rounded-lg bg-secondary p-3"><dt class="text-xs font-semibold uppercase text-secondary">{{ __('Step to investigate') }}</dt><dd class="mt-1 font-medium text-primary">{{ $failureGuidance['failed_step'] ?? __('Finalization') }}</dd></div>
                </dl>
                <div class="mt-4 flex flex-wrap gap-3"><a href="#deployment-log" class="button primary">{{ __('Inspect deployment log') }}</a><a href="{{ route('repositories.edit', $build->repository) }}" class="button secondary">{{ __('Review deployment settings') }}</a><a href="{{ route('websites.show', $build->repository->website) }}" class="button secondary">{{ __('Inspect website health') }}</a></div>
            </section>
        @endif
        @if ($rollbackCandidate)
            @can('rollback', $rollbackCandidate)
                <section class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-5 text-amber-950">
                    <h2 class="font-black">{{ __('Restore the last known-good release') }}</h2>
                    <p class="mt-1 text-sm">{{ __('Build #:id succeeded :time and its retained artifact can be switched live without rebuilding.', ['id' => $rollbackCandidate->id, 'time' => $rollbackCandidate->finished_at?->diffForHumans() ?? __('previously')]) }}</p>
                    <form method="POST" action="{{ route('builds.rollback', $rollbackCandidate) }}" class="mt-4">
                        @csrf
                        <button type="submit" class="button primary" onclick="return confirm({{ Illuminate\Support\Js::from(__('Immediately restore the last known-good release?')) }})">{{ __('Restore build #:id', ['id' => $rollbackCandidate->id]) }}</button>
                    </form>
                </section>
            @endcan
        @endif
    @endif

    @if ($build->status === \App\Models\Build::STATUS_SUCCEEDED)
        <section class="mt-4 rounded-lg border border-primary bg-primary p-5" aria-labelledby="deployment-health-title">
            <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Post-deployment verification') }}</p><h2 id="deployment-health-title" class="mt-1 text-lg font-black text-primary">{{ __('Application health') }}</h2><p class="mt-1 text-sm text-secondary">{{ $website->health_check_enabled ? __('The deployment health path is :path. Current monitor state: :state.', ['path' => $website->health_check_path, 'state' => str($website->health_status)->headline()]) : __('Continuous health monitoring is disabled. Enable it to detect regressions after deployment.') }}</p></div><span @class(['rounded-full px-3 py-1 text-xs font-bold uppercase','bg-green-100 text-green-700' => $website->health_status === 'healthy','bg-red-100 text-red-700' => $website->health_status === 'unhealthy','bg-gray-100 text-gray-700' => ! in_array($website->health_status, ['healthy','unhealthy'], true)])>{{ $website->health_check_enabled ? str($website->health_status)->headline() : __('Disabled') }}</span></div>
            <div class="mt-4 flex flex-wrap gap-3"><a href="https://{{ $website->url }}" target="_blank" rel="noopener noreferrer" class="button primary">{{ __('Open live website') }}</a><a href="{{ route('websites.show', $website) }}#health-history-heading" class="button secondary">{{ __('View health history') }}</a>@if($website->health_check_enabled)<form method="POST" action="{{ route('websites.health.check', $website) }}">@csrf<button type="submit" class="button secondary">{{ __('Run health check now') }}</button></form>@else<a href="{{ route('websites.edit', $website) }}" class="button secondary">{{ __('Enable health monitoring') }}</a>@endif</div>
        </section>
    @endif

    @if ($build->status === \App\Models\Build::STATUS_CANCELED)
        <div class="mt-6 rounded border border-amber-300 bg-amber-50 p-4 text-amber-800">
            {{ __('This deployment was canceled before it completed.') }}
        </div>
    @endif

    <section id="deployment-log" class="mt-8">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-2xl font-bold text-primary">{{ __('Deployment log') }}</h2>
            @if ($deploymentLog)
                <div class="flex items-center gap-3">
                    <span class="text-xs text-secondary">{{ __('Updated :time', ['time' => $deploymentLog->updated_at->diffForHumans()]) }}</span>
                    <a href="{{ route('builds.log.download', $build) }}" class="text-sm font-medium text-ternary hover:underline">
                        {{ __('Download log') }}
                    </a>
                </div>
            @endif
        </div>

        @if ($deploymentLog)
            <pre class="max-h-[36rem] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-950 p-5 font-mono text-xs leading-5 text-slate-100">{{ $deploymentLog->log }}</pre>
        @elseif ($shouldPoll)
            <div class="rounded-lg border border-primary bg-primary p-6 text-center">
                <p class="font-medium text-primary">{{ __('Waiting for deployment output…') }}</p>
                <p class="mt-1 text-sm text-secondary">{{ __('This view updates automatically while the deployment runs.') }}</p>
            </div>
        @else
            <x-lists.empty
                :title="__('No deployment log yet')"
                :description="__('No remote deployment output was received.')"
            />
        @endif
    </section>
</div>
