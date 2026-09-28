@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Alert destinations')" :description="__('Where incident alerts go. Destinations belong to :account and any project’s monitors can use them.', ['account' => $project->account->name])">
    @include('monitoring._alerts-tabs')

    @if ($destinations === [])
        <x-signal.ui.empty-state icon="share" :title="__('No alert destinations yet')" :description="__('Send alerts to a member’s email, a signed webhook, Slack, Microsoft Teams, Discord or PagerDuty.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Alert destinations') }}">
                @foreach ($destinations as $destination)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <a href="{{ route('monitoring.destinations.show', [$project, $destination->id]) }}" class="font-extrabold text-ink hover:underline">{{ $destination->name }}</a>
                            <p class="mt-0.5 break-all text-xs text-muted">{{ $destination->type->label() }} · {{ $destination->targetLabel() }} · {{ trans_choice(':count monitor|:count monitors', $destination->monitors_count ?? 0, ['count' => $destination->monitors_count ?? 0]) }}</p>
                        </div>
                        <x-signal.ui.badge :tone="$destination->enabled ? 'success' : 'neutral'">{{ $destination->enabled ? __('On') : __('Off') }}</x-signal.ui.badge>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif

    @if ($canManage)
        <x-slot:actions>
            <x-signal.ui.button :href="request()->fullUrlWithQuery(['dialog' => 'add-destination'])" variant="primary" data-modal-trigger="add-destination">{{ __('Add a destination') }}</x-signal.ui.button>
        </x-slot:actions>
        <x-signal.overlays.form-modal id="add-destination" :title="__('Add a destination')" :description="__('Account admins manage destinations. Pick which monitors use one on each monitor’s settings.')" :action="route('monitoring.destinations.store', $project)" :submit="__('Add destination')" form-class="grid gap-5">
            @include('monitoring._destination-fields', ['destination' => null])
        </x-signal.overlays.form-modal>
    @endif
</x-signal.layouts.project>
