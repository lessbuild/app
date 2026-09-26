<x-signal.layouts.platform :title="__('Feature rollouts')" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces">
    <x-signal.ui.page-header :eyebrow="$workspace->name" :title="__('Feature rollouts')" :description="__('Choose which shared enhancements your workspace uses, within the availability set by Buildpusher.')">
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.admin', $workspace)" variant="secondary">{{ __('Workspace management') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.alert tone="info" class="mt-6">
        {{ __('These switches control shared views only. App permissions and subscription limits still apply. Turning a view off keeps native app tools available and preserves settings, history, queued work, and webhook delivery.') }}
    </x-signal.ui.alert>

    <div class="mt-6 grid gap-5 lg:grid-cols-2">
        @foreach ($features as $key => $feature)
            @php($measurements = $metrics->get($key, collect()))
            <x-signal.ui.card as="section" class="p-5" :aria-labelledby="'rollout-'.$key">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h2 id="rollout-{{ $key }}" class="text-lg font-extrabold text-ink">{{ __($feature['label']) }}</h2>
                    <x-signal.ui.badge :tone="$feature['enabled'] ? 'success' : 'neutral'">{{ $feature['enabled'] ? __('Enabled') : __('Paused') }}</x-signal.ui.badge>
                </div>
                <p class="mt-2 text-sm text-muted">{{ __($feature['description']) }}</p>
                @if (! $feature['available'])
                    <p class="mt-3 text-sm text-muted">{{ __('Buildpusher has not made this view available to this workspace. Your preference cannot override that restriction.') }}</p>
                @elseif (! $feature['managed'])
                    <p class="mt-3 text-sm text-muted">{{ __('Buildpusher currently manages this setting. Any saved workspace preference is retained for a future rollout.') }}</p>
                @endif
                @if ($feature['managed'])
                    <form method="POST" action="{{ route('core.workspace.feature-rollouts.update', [$workspace, $key]) }}" class="mt-5 flex flex-wrap items-end gap-3">
                        @csrf
                        @method('PATCH')
                        <x-signal.ui.select-field name="state" :id="'rollout-state-'.$key" :label="__('Workspace preference')" field-class="min-w-0 flex-1">
                            <option value="default" @selected($feature['override'] === null)>{{ $feature['default'] ? __('Use default (enabled)') : __('Use default (paused)') }}</option>
                            <option value="enabled" @selected($feature['override'] === true) @disabled(! $feature['available'])>{{ __('Enabled') }}</option>
                            <option value="disabled" @selected($feature['override'] === false)>{{ __('Paused') }}</option>
                        </x-signal.ui.select-field>
                        <x-signal.ui.button type="submit" variant="primary">{{ __('Save preference') }}</x-signal.ui.button>
                    </form>
                @endif
                <h3 class="mt-6 text-sm font-bold text-ink">{{ __('Last 14 UTC days') }}</h3>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                    @foreach (['exposed' => __('Opened'), 'completed' => __('Completed'), 'degraded' => __('Partial results'), 'failed' => __('Failed'), 'rejected' => __('Rejected requests'), 'held' => __('Held by rollout')] as $outcome => $label)
                        <div><dt class="text-muted">{{ $label }}</dt><dd class="mt-1 font-bold text-ink">{{ number_format($measurements->sum($outcome.'_count')) }}</dd></div>
                    @endforeach
                </dl>
                <p class="mt-3 text-xs text-muted">{{ __('Counts describe shared page requests, including repeats. Partial results mean a product source was unavailable. They are not deployment or delivery success rates.') }}</p>
            </x-signal.ui.card>
        @endforeach
    </div>

    <section class="mt-8" aria-labelledby="rollout-changes-heading">
        <h2 id="rollout-changes-heading" class="text-lg font-extrabold text-ink">{{ __('Recent preference changes') }}</h2>
        @if ($changes->isEmpty())
            <x-signal.ui.empty-state class="mt-4" :title="__('Using the configured defaults')" :description="__('Workspace preference changes will appear here with the administrator and time.')" />
        @else
            <x-signal.ui.card class="mt-4 divide-y divide-line">
                @foreach ($changes as $change)
                    <div class="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                        <div>
                            <p class="font-bold text-ink">{{ __($features[$change->feature]['label']) }} · {{ $change->enabled === null ? __('Use default') : ($change->enabled ? __('Enabled') : __('Paused')) }}</p>
                            <p class="mt-1 text-muted">{{ $change->actor?->name ?? __('Former administrator') }}</p>
                        </div>
                        <time class="text-muted" datetime="{{ $change->created_at->toIso8601String() }}">{{ $change->created_at->utc()->format('Y-m-d H:i:s') }} UTC</time>
                    </div>
                @endforeach
            </x-signal.ui.card>
        @endif
    </section>
</x-signal.layouts.platform>
