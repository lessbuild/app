@php($project = $overview->project)

@if ($build->isActive())
    @push('head')<meta http-equiv="refresh" content="5">@endpush
@endif

<x-signal.layouts.project :overview="$overview" :title="__('Deploy #:id', ['id' => $build->id])" :description="$build->repository->name.' → '.$build->website->name">
    @foreach (['rollback', 'deploy'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach

    <x-signal.ui.card class="grid gap-4 p-5">
        <div class="flex flex-wrap items-center gap-3">
            @include('deploy._build-status', ['status' => $build->status])
            @if ($build->revision)
                @php($commitUrl = $build->repository->revisionUrl($build->revision))
                <a @if ($commitUrl) href="{{ $commitUrl }}" target="_blank" rel="noopener" @endif class="font-mono text-sm text-primary">{{ $build->shortRevision() }}</a>
            @endif
            <span class="text-sm text-ink">{{ $build->commit_message }}</span>
            <x-signal.ui.button :href="route('deploy.builds.compare', [$project, $build->id])" variant="quiet" size="sm" class="ml-auto">{{ __('Compare') }}</x-signal.ui.button>
        </div>
        <dl class="grid gap-4 text-sm sm:grid-cols-4">
            <div><dt class="text-xs text-muted">{{ __('Started by') }}</dt><dd class="mt-1">{{ $build->requester?->name ?? __('A push') }} · {{ __(ucfirst($build->trigger_source)) }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Started') }}</dt><dd class="mt-1">{{ $build->started_at?->diffForHumans() ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Took') }}</dt><dd class="mt-1">{{ $build->started_at && $build->finished_at ? $build->started_at->diffForHumans($build->finished_at, \Carbon\CarbonInterface::DIFF_ABSOLUTE) : '—' }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Release') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ $build->release_name ?? '—' }}</dd></div>
        </dl>
        @if ($build->rolledBackFrom)<p class="text-sm text-muted">{{ __('Rolled back to the release from deploy #:id.', ['id' => $build->rolledBackFrom->id]) }}</p>@endif
        @if ($build->redeployedFrom)<p class="text-sm text-muted">{{ __('Redeploy of #:id.', ['id' => $build->redeployedFrom->id]) }}</p>@endif
        @if ($build->promotedFrom)<p class="text-sm text-muted">{{ __('Promoted from deploy #:id in :environment.', ['id' => $build->promotedFrom->id, 'environment' => $build->promotedFrom->environment->name ?? '—']) }}@if ($build->promotion_note) {{ __('Note: :note', ['note' => $build->promotion_note]) }}@endif</p>@endif
        @foreach ($build->promotions as $promotion)
            <p class="text-sm text-muted">{{ __('Promoted to :environment as', ['environment' => $promotion->environment->name ?? '—']) }} <a href="{{ route('deploy.builds.show', [$project, $promotion->id]) }}" class="font-bold text-primary hover:underline">#{{ $promotion->id }}</a>.</p>
        @endforeach
        @if ($build->failure_message)<p class="text-sm text-danger">{{ $build->failure_message }}</p>@endif
        @if ($build->approval_note)<p class="text-sm text-muted">{{ __('Note: :note', ['note' => $build->approval_note]) }}</p>@endif

        @if ($build->status === 'awaiting_approval' && $canApprove)
            <form method="POST" action="{{ route('deploy.builds.review', [$project, $build->id]) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <x-signal.ui.input-field name="note" :label="__('Note (optional)')" maxlength="1000" />
                <x-signal.ui.button type="submit" name="decision" value="approve" variant="primary">{{ __('Approve') }}</x-signal.ui.button>
                <x-signal.ui.button type="submit" name="decision" value="reject" variant="secondary">{{ __('Reject') }}</x-signal.ui.button>
            </form>
        @elseif ($build->status === 'awaiting_approval')
            <p class="text-sm text-muted">{{ __('Waiting for someone else with deploy rights to approve it.') }}</p>
        @endif

        @if ($promotionTargets->isNotEmpty())
            <form method="POST" action="{{ route('deploy.builds.promote', [$project, $build->id]) }}" class="flex flex-wrap items-end gap-3 border-t border-line pt-4">
                @csrf
                <x-signal.ui.select-field name="environment_id" :label="__('Promote this commit to')">
                    @foreach ($promotionTargets as $target)
                        <option value="{{ $target->id }}">{{ $target->name }}</option>
                    @endforeach
                </x-signal.ui.select-field>
                <x-signal.ui.input-field name="note" :label="__('Note (optional)')" maxlength="2000" />
                <x-signal.ui.button type="submit" variant="primary">{{ __('Promote') }}</x-signal.ui.button>
            </form>
        @endif
        @foreach (['promote'] as $key)
            @error($key)<p class="text-sm text-danger" role="alert">{{ $message }}</p>@enderror
        @endforeach

        @if ($canDeploy)
            <div class="flex flex-wrap gap-2">
                @if ($build->isActive())
                    <form method="POST" action="{{ route('deploy.builds.cancel', [$project, $build->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Cancel deploy') }}</x-signal.ui.button></form>
                @endif
                @if (! $build->isActive() && $build->revision)
                    <form method="POST" action="{{ route('deploy.builds.redeploy', [$project, $build->id]) }}">@csrf<x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Redeploy this commit') }}</x-signal.ui.button></form>
                @endif
                @if ($build->status === 'succeeded' && $build->release_name)
                    <form method="POST" action="{{ route('deploy.builds.rollback', [$project, $build->id]) }}">@csrf<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Make this release live again') }}</x-signal.ui.button></form>
                @endif
            </div>
        @endif
    </x-signal.ui.card>

    @if (in_array($build->status, ['deploying', 'running', 'succeeded', 'failed', 'canceled'], true) && $build->trigger_source !== 'rollback')
        <x-signal.ui.card class="p-5">
            <ol class="grid gap-2 text-sm sm:grid-cols-3">
                @foreach ($stages as $index => $title)
                    @php($done = $build->setup_stage > $index)
                    <li class="flex items-center gap-2 {{ $done ? 'text-ink' : 'text-muted' }}"><span aria-hidden="true">{{ $done ? '✓' : '·' }}</span> {{ __($title) }}</li>
                @endforeach
            </ol>
        </x-signal.ui.card>
    @endif

    <x-signal.ui.settings-section :title="__('Log')" :description="$build->isActive() ? __('Updates every few seconds while it runs.') : __('The end of the deployment log.')">
        @if ($build->log)
            <x-signal.ui.code-block class="m-4 max-h-[32rem] overflow-auto whitespace-pre-wrap sm:m-6" :code="$build->log" />
        @else
            <p class="p-4 text-sm text-muted sm:p-6">{{ __('No log yet.') }}</p>
        @endif
    </x-signal.ui.settings-section>
</x-signal.layouts.project>
