<div @if ($shouldPoll) wire:poll.5s @endif>
    <dl class="mt-6 grid gap-4 rounded-lg border border-primary bg-primary p-5 sm:grid-cols-2 lg:grid-cols-6">
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
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Last heartbeat') }}</dt>
            <dd class="mt-1 text-primary">{{ $build->last_heartbeat_at?->format('Y-m-d H:i:s T') ?? __('Not received') }}</dd>
        </div>
    </dl>

    @if ($build->status === \App\Models\Build::STATUS_TIMING_OUT)
        <div class="mt-4 rounded border border-amber-300 bg-amber-50 p-4 text-amber-800">
            <p>{{ __('This deployment stopped reporting progress. Lessbuild is safely stopping its remote process before allowing another deployment.') }}</p>
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

    @if ($build->commit_message)
        <div class="mt-4 rounded-lg border border-primary bg-primary p-4">
            <p class="text-xs font-semibold uppercase text-secondary">{{ __('Commit message') }}</p>
            <p class="mt-1 whitespace-pre-wrap break-words text-sm text-primary">{{ $build->commit_message }}</p>
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

    @if ($build->status === \App\Models\Build::STATUS_QUEUED || ($build->status === \App\Models\Build::STATUS_RUNNING && $build->remote_process_id && $build->remote_process_path))
        <form method="POST" action="{{ route('builds.cancel', $build) }}" class="mt-4">
            @csrf
            <button
                type="submit"
                class="button primary"
                onclick="return confirm({{ Illuminate\Support\Js::from($build->status === \App\Models\Build::STATUS_QUEUED
                    ? __('Remove this deployment from the queue?')
                    : __('Stop this deployment on the remote server?')) }})"
            >
                {{ $build->status === \App\Models\Build::STATUS_QUEUED ? __('Cancel queued deployment') : __('Cancel deployment') }}
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

    @if ($build->status === \App\Models\Build::STATUS_FAILED && $build->failure_message)
        <div class="mt-6 rounded border border-red-300 bg-red-50 p-4 text-red-800">
            <strong>{{ __('Deployment failed:') }}</strong> {{ $build->failure_message }}
        </div>
    @endif

    @if ($build->status === \App\Models\Build::STATUS_CANCELED)
        <div class="mt-6 rounded border border-amber-300 bg-amber-50 p-4 text-amber-800">
            {{ __('This deployment was canceled before it completed.') }}
        </div>
    @endif

    <section class="mt-8">
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
