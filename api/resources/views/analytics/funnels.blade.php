@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Funnels')" :description="__('Steps visitors take in order, such as pricing, sign-up and first project, and where they drop off. Worked out from the last 30 days of events.')">
    @if ($site === null)
        <x-signal.ui.empty-state icon="view-grid" :title="__('Add a site first')" :description="__('Funnels belong to a site.')" />
    @else
        @include('analytics._site-picker')
        @if ($canManage)
            <x-slot:actions>
                <x-signal.ui.button :href="route('analytics.funnels', [$project, 'site' => $site->id, 'dialog' => 'new-funnel'])" variant="primary" data-modal-trigger="new-funnel">{{ __('Add a funnel') }}</x-signal.ui.button>
            </x-slot:actions>
            <x-signal.overlays.form-modal id="new-funnel" :title="__('Add a funnel')" :description="__('Two to six steps. A visitor counts at a step once they’ve done every step before it, in order.')" :action="route('analytics.funnels.store', [$project, $site->id])" :submit="__('Add funnel')" form-class="grid items-start gap-4 sm:grid-cols-3">
                @include('analytics._funnel-fields', ['funnel' => null, 'prefix' => 'new-funnel'])
            </x-signal.overlays.form-modal>
        @endif

        @forelse ($funnels as $row)
            @php($funnel = $row['funnel'])
            @php($start = max(1, $row['report'][0]['visitors'] ?? 0))
            <x-signal.ui.card as="section" class="grid gap-4 p-5" aria-labelledby="funnel-{{ $funnel->id }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="funnel-{{ $funnel->id }}" class="text-lg font-extrabold text-ink">{{ $funnel->name }}</h2>
                    @if ($canManage)
                        <div class="flex gap-2">
                            <x-signal.ui.button :href="route('analytics.funnels', [$project, 'site' => $site->id, 'dialog' => 'edit-funnel-'.$funnel->id])" variant="quiet" size="sm" :data-modal-trigger="'edit-funnel-'.$funnel->id">{{ __('Edit') }}</x-signal.ui.button>
                            <form method="POST" action="{{ route('analytics.funnels.destroy', [$project, $site->id, $funnel->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Remove') }}</x-signal.ui.button></form>
                        </div>
                    @endif
                </div>
                <ol class="grid gap-3">
                    @foreach ($row['report'] as $step)
                        <li class="grid items-center gap-x-3 gap-y-1 text-sm sm:grid-cols-[14rem_1fr_9rem]">
                            <span class="font-semibold text-ink">{{ $loop->iteration }}. {{ $step['label'] }}</span>
                            <x-signal.ui.progress :value="$step['visitors']" :max="$start" role="meter" :label="__(':step: :count visitors', ['step' => $step['label'], 'count' => $step['visitors']])" />
                            <span class="tabular-nums text-muted sm:text-right"><span class="font-bold text-ink">{{ number_format($step['visitors']) }}</span> · {{ $step['of_start'] }}%@if ($step['of_previous'] !== null)<span class="block text-xs">{{ __(':rate% carried on', ['rate' => $step['of_previous']]) }}</span>@endif</span>
                        </li>
                    @endforeach
                </ol>
                @if ($canManage)
                    <x-signal.overlays.form-modal :id="'edit-funnel-'.$funnel->id" :title="__('Edit funnel')" :action="route('analytics.funnels.update', [$project, $site->id, $funnel->id])" method="PUT" :submit="__('Save')" form-class="grid items-start gap-4 sm:grid-cols-3">
                        @include('analytics._funnel-fields', ['funnel' => $funnel, 'prefix' => 'edit-funnel-'.$funnel->id])
                    </x-signal.overlays.form-modal>
                @endif
            </x-signal.ui.card>
        @empty
            <x-signal.ui.empty-state icon="filter" :title="__('No funnels yet')" :description="__('Add the steps you want visitors to take, and see how many make it through each one.')" />
        @endforelse
    @endif
</x-signal.layouts.project>
