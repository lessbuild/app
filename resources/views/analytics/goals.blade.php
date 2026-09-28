@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Goals')" :description="__('Pages and custom events that count as conversions. A new or changed goal counts from that moment on; earlier conversions keep the definition they were counted under.')">
    @if ($site === null)
        <x-signal.ui.empty-state icon="view-grid" :title="__('Add a site first')" :description="__('Goals belong to a site.')" />
    @else
        @include('analytics._site-picker')

        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line" aria-label="{{ __('Goals for :site', ['site' => $site->name]) }}">
                @forelse ($goals as $goal)
                    <li class="grid gap-3 px-5 py-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 font-extrabold text-ink">{{ $goal->name }} <x-signal.ui.badge :tone="$goal->active ? 'success' : 'neutral'">{{ $goal->active ? __('Counting') : __('Paused') }}</x-signal.ui.badge></p>
                                <p class="mt-0.5 text-xs text-muted">{{ $goal->kind === 'event' ? __('Custom event') : __('Page') }} · {{ $goal->match_type === 'prefix' ? __('starts with') : __('exactly') }} <code class="rounded bg-surface-muted px-1 text-ink">{{ $goal->match_value }}</code></p>
                            </div>
                            @if ($canManage)
                                <x-signal.ui.button variant="quiet" size="sm" data-modal-trigger="delete-goal-{{ $goal->id }}">{{ __('Remove') }}</x-signal.ui.button>
                                <x-signal.overlays.delete-confirmation :id="'delete-goal-'.$goal->id" :route="route('analytics.goals.destroy', [$project, $site->id, $goal->id])" :title="__('Remove :goal?', ['goal' => $goal->name])" :description="__('Its conversions disappear from reports.')" :submit-label="__('Remove')" />
                            @endif
                        </div>
                        @if ($canManage)
                            <details>
                                <summary class="cursor-pointer text-sm font-bold text-primary">{{ __('Edit') }}</summary>
                                <form method="POST" action="{{ route('analytics.goals.update', [$project, $site->id, $goal->id]) }}" class="mt-3 grid gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:grid-cols-2">
                                    @csrf
                                    @method('PUT')
                                    @include('analytics._goal-fields', ['goal' => $goal])
                                    <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="secondary" size="sm">{{ __('Save goal') }}</x-signal.ui.button></div>
                                </form>
                            </details>
                        @endif
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-muted">{{ __('No goals yet. A thank-you page or a “signup” event makes a good first goal.') }}</li>
                @endforelse
            </ul>
        </x-signal.ui.card>

        @if ($canManage)
            <x-slot:actions>
                <x-signal.ui.button :href="route('analytics.goals', [$project, 'site' => $site->id, 'dialog' => 'add-goal'])" variant="primary" data-modal-trigger="add-goal">{{ __('Add a goal') }}</x-signal.ui.button>
            </x-slot:actions>
            <x-signal.overlays.modal id="add-goal" :title="__('Add a goal to :site', ['site' => $site->name])" :description="__('Custom events are sent with window.buildpusher.track(\'name\').')">
                <form method="POST" action="{{ route('analytics.goals.store', [$project, $site->id]) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <input type="hidden" name="_modal" value="add-goal">
                    @include('analytics._goal-fields', ['goal' => null])
                    <div class="flex justify-end sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Add goal') }}</x-signal.ui.button></div>
                </form>
            </x-signal.overlays.modal>
        @endif
    @endif
</x-signal.layouts.project>
