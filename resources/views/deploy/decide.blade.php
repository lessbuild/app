@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="$decision === 'approve' ? __('Approve deploy #:id', ['id' => $build->id]) : __('Reject deploy #:id', ['id' => $build->id])" :description="__(':repository to :environment', ['repository' => $build->repository->name, 'environment' => $build->environment->name ?? '—'])">
    <x-signal.ui.card class="grid gap-4 p-5 sm:p-6">
        <dl class="grid gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-xs text-muted">{{ __('Started by') }}</dt><dd class="mt-1">{{ $build->requester?->name ?? __('A push') }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Commit') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ $build->revision ? substr($build->revision, 0, 12) : __('Latest on :branch', ['branch' => $build->repository->branch]) }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('Status') }}</dt><dd class="mt-1">{{ __(ucfirst(str_replace('_', ' ', $build->status))) }}</dd></div>
        </dl>
        @if ($build->commit_message)<p class="whitespace-pre-line text-sm text-muted">{{ $build->commit_message }}</p>@endif

        @if ($build->status !== \App\Models\Build::STATUS_AWAITING_APPROVAL)
            <x-signal.ui.alert tone="info">{{ __('This deploy isn’t waiting for approval any more.') }}</x-signal.ui.alert>
        @elseif (! $canApprove)
            <x-signal.ui.alert tone="warning">{{ $reason ?: __('You can’t approve this deploy.') }}</x-signal.ui.alert>
        @else
            <form method="POST" action="{{ route('deploy.builds.review', [$project, $build->id]) }}" class="grid gap-3">
                @csrf
                <input type="hidden" name="decision" value="{{ $decision }}">
                <x-signal.ui.input-field name="note" :label="__('Note (optional)')" maxlength="1000" />
                <div class="flex flex-wrap gap-2">
                    <x-signal.ui.button type="submit" :variant="$decision === 'approve' ? 'primary' : 'danger'">{{ $decision === 'approve' ? __('Approve and deploy') : __('Reject') }}</x-signal.ui.button>
                    <x-signal.ui.button :href="route('deploy.builds.decide', [$project, $build->id, 'decision' => $decision === 'approve' ? 'reject' : 'approve'])" variant="quiet">{{ $decision === 'approve' ? __('Reject instead') : __('Approve instead') }}</x-signal.ui.button>
                </div>
            </form>
        @endif
        <p class="text-sm"><a class="font-bold text-primary hover:underline" href="{{ route('deploy.builds.show', [$project, $build->id]) }}">{{ __('See the whole deploy') }}</a></p>
    </x-signal.ui.card>
</x-signal.layouts.project>
