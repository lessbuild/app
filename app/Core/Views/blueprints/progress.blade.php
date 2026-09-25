<x-signal.layouts.platform :title="__('Blueprint progress')" :account-user="$user" :current-workspace="$workspace" :workspaces="$workspaces">
    <x-signal.ui.page-header :eyebrow="$project->name" :title="__('Blueprint progress')" :description="__('Each app records its own result. An interrupted application resumes the accepted version and preserves completed work.')">
        <x-slot:actions><x-signal.ui.button :href="route('core.projects.show', [$workspace, $project])" variant="secondary">{{ __('Project setup') }}</x-signal.ui.button></x-slot:actions>
    </x-signal.ui.page-header>
    <div class="mt-6 grid gap-4 md:grid-cols-3">
        @foreach ($run->steps as $step)
            <x-signal.ui.card class="space-y-3 p-5">
                <h2 class="font-bold text-ink">{{ config('platform.products.'.$step->product.'.label', str($step->product)->headline()) }}</h2>
                <x-signal.ui.badge :tone="$step->status === 'completed' ? 'success' : ($step->status === 'blocked' ? 'warning' : 'neutral')">{{ str($step->status)->headline() }}</x-signal.ui.badge>
                @if ($step->last_error_code)<p class="text-sm text-muted">{{ \App\Core\Services\Blueprints\BlueprintMessages::reason($step->last_error_code) }}</p>@endif
                @if ($step->completed_at)<p class="text-xs text-muted">{{ __('Provisioning recorded :time', ['time' => $step->completed_at->diffForHumans()]) }}</p>@endif
            </x-signal.ui.card>
        @endforeach
    </div>
    <x-signal.ui.alert tone="info" class="mt-5">{{ __('Open project setup to supply secrets, connect resources, verify domains, install trackers, and perform a first deployment. Blueprint provisioning does not claim those checks have passed.') }}</x-signal.ui.alert>
    <div class="mt-5 flex flex-wrap gap-3">
        <x-signal.ui.button :href="route('core.workspace.blueprints.runs.show', [$workspace, $run])" variant="secondary">{{ __('Refresh progress') }}</x-signal.ui.button>
        @if ($canRetry && $run->steps->contains(fn ($step) => in_array($step->status, ['blocked', 'waiting'], true)))
            <form method="POST" action="{{ route('core.workspace.blueprints.runs.retry', [$workspace, $run]) }}">@csrf<x-signal.ui.button type="submit">{{ __('Resume saved application') }}</x-signal.ui.button></form>
        @endif
    </div>
</x-signal.layouts.platform>
