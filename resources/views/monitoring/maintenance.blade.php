@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Maintenance')" :description="__('During a maintenance window no monitor in :account opens an incident or sends alerts. Checks keep running and are recorded.', ['account' => $project->account->name])">
    @if ($windows->isEmpty())
        <x-signal.ui.empty-state icon="clock" :title="__('No maintenance scheduled')" :description="__('Schedule a window before planned work so expected failures don’t page anyone.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Maintenance windows') }}">
                @foreach ($windows as $window)
                    @php($state = $window->ends_at->isPast() ? 'past' : ($window->starts_at->isPast() ? 'active' : 'upcoming'))
                    <li class="grid gap-3 px-5 py-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 font-extrabold text-ink">{{ $window->name }}
                                    <x-signal.ui.badge :tone="match ($state) { 'active' => 'warning', 'upcoming' => 'info', default => 'neutral' }">{{ match ($state) { 'active' => __('In progress'), 'upcoming' => __('Upcoming'), default => __('Finished') } }}</x-signal.ui.badge>
                                </p>
                                <p class="mt-0.5 text-xs text-muted">{{ $window->starts_at->format('Y-m-d H:i') }} – {{ $window->ends_at->format('Y-m-d H:i') }} UTC{{ $window->reason ? ' · '.$window->reason : '' }}</p>
                            </div>
                            @if ($canManage)
                                <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="delete-window-{{ $window->id }}">{{ __('Delete') }}</x-signal.ui.button>
                                <x-signal.overlays.delete-confirmation :id="'delete-window-'.$window->id" :route="route('monitoring.maintenance.destroy', [$project, $window->id])" :title="__('Delete :window?', ['window' => $window->name])" :description="__('Monitors alert normally again during this time.')" :submit-label="__('Delete')" />
                            @endif
                        </div>
                        @if ($canManage && $state !== 'past')
                            <details>
                                <summary class="cursor-pointer text-sm font-bold text-primary">{{ __('Edit') }}</summary>
                                <form method="POST" action="{{ route('monitoring.maintenance.update', [$project, $window->id]) }}" class="mt-3 grid gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:grid-cols-2">
                                    @csrf
                                    @method('PUT')
                                    @include('monitoring._maintenance-fields', ['window' => $window])
                                    <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save') }}</x-signal.ui.button></div>
                                </form>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif

    @if ($canManage)
        <x-signal.ui.settings-section :title="__('Schedule maintenance')" :description="__('Times are in UTC.')">
            <form method="POST" action="{{ route('monitoring.maintenance.store', $project) }}" class="grid gap-4 p-4 sm:grid-cols-2 sm:p-6">
                @csrf
                @include('monitoring._maintenance-fields', ['window' => null])
                <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Schedule') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
