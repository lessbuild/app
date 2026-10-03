@php($project = $overview->project)
@php($example = "version: 2\nenvironments:\n  production:\n    type: production\n    placement: web\n    runtime:\n      type: php\n    processes:\n      queue: { type: worker, command: 'php artisan queue:work', replicas: 2 }\n    resources:\n      database: { type: mysql, managed: true }\n    variables:\n      STRIPE_SECRET: { secret_ref: stripe, scope: runtime }\n    deploy:\n      repository: app")

<x-signal.layouts.project :overview="$overview" :title="__('Configuration')" :description="__('Describe environments in a YAML document, see exactly what it would change, then apply it as a review. Objects the document doesn’t mention are left alone.')">
    @foreach (['document', 'bindings', 'plan', 'review'] as $key)
        @error($key)<x-signal.ui.alert tone="danger" role="alert">{{ $message }}</x-signal.ui.alert>@enderror
    @endforeach

    @if (is_array($plan))
        <x-signal.ui.card class="grid gap-4 p-5">
            <h2 class="font-extrabold text-ink">{{ __('Plan') }}</h2>
            @include('deploy._configuration-changes', ['plan' => $plan])
            @unless ($plan['apply_available'])
                <p class="text-sm text-warning">{{ __('Some objects exist but configuration doesn’t own them. Add adopt: true to take them over, then plan again.') }}</p>
            @endunless
        </x-signal.ui.card>
    @endif

    <x-signal.ui.settings-section :title="__('Document')" :description="__('Version 2 documents, as in Deployer. Names in placements, repositories and secret refs are bound to records below.')">
        <form method="POST" action="{{ route('deploy.configuration.plan', $project) }}" class="grid gap-4 p-4 sm:p-6">
            @csrf
            <x-signal.ui.textarea-field name="document" :label="__('Document (YAML)')" rows="16" class="font-mono text-sm" :value="old('document', $example)" required />
            <x-signal.ui.textarea-field name="bindings" :label="__('Bindings (JSON)')" rows="4" class="font-mono text-sm" :value="old('bindings', '{\"placements\": {\"web\": 1}, \"repositories\": {\"app\": 1}, \"secrets\": {\"stripe\": 1}}')" :description="__('Map each name to an ID from the lists below.')" />
            <div class="flex flex-wrap gap-2">
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Plan changes') }}</x-signal.ui.button>
                <x-signal.ui.button type="submit" variant="primary" formaction="{{ route('deploy.configuration.reviews.store', $project) }}">{{ __('Create review') }}</x-signal.ui.button>
            </div>
        </form>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Binding IDs')" :description="__('Placements are websites, repositories are this project’s repositories, and secrets are secret variables.')">
        <div class="grid gap-4 p-4 text-sm sm:grid-cols-3 sm:p-6">
            @foreach ([__('Websites') => $websites->map(fn ($website) => [$website->id, $website->name]), __('Repositories') => $repositories->map(fn ($repository) => [$repository->id, $repository->name.' → '.$repository->website->name]), __('Secrets') => $secrets->map(fn ($secret) => [$secret->id, $secret->key.' ('.$secret->environment->name.')'])] as $heading => $rows)
                <div>
                    <h3 class="mb-2 font-bold text-ink">{{ $heading }}</h3>
                    <ul class="grid gap-1">
                        @forelse ($rows as [$id, $label])
                            <li><span class="font-mono text-xs text-muted">{{ $id }}</span> {{ $label }}</li>
                        @empty
                            <li class="text-muted">{{ __('None') }}</li>
                        @endforelse
                    </ul>
                </div>
            @endforeach
        </div>
    </x-signal.ui.settings-section>

    <x-signal.ui.settings-section :title="__('Recent reviews')" :description="__('Reviews last 15 minutes; applying one gives a receipt that follows its deploys.')">
        @if ($reviews->isEmpty())
            <p class="p-4 text-sm text-muted sm:p-6">{{ __('No reviews yet.') }}</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($reviews as $item)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm sm:px-6">
                        <a href="{{ $item->application ? route('deploy.configuration.applications.show', [$project, $item->application->id]) : route('deploy.configuration.reviews.show', [$project, $item->id]) }}" class="font-bold text-primary hover:underline">{{ __('Review #:id', ['id' => $item->id]) }}</a>
                        <span class="text-muted">{{ $item->requester->name }} · {{ $item->created_at?->diffForHumans() }} · {{ trans_choice(':count change|:count changes', count($item->summary['changes'])) }}</span>
                        <x-signal.ui.badge :tone="$item->application ? 'success' : ($item->expires_at->isPast() ? 'neutral' : 'info')">{{ $item->application ? __(str_replace('_', ' ', ucfirst($item->application->status))) : ($item->expires_at->isPast() ? __('Expired') : __('Ready to apply')) }}</x-signal.ui.badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-signal.ui.settings-section>

    @can('manageDeploy', $project)
    <x-signal.ui.settings-section id="workflow" :title="__('Workflow (version 1)')" :description="__('Deployer’s workflow format: scheduled deploys, scaling, scaling schedules and processes per environment, applied all at once. Also at PUT /api/v1/projects/{project}/workflow.')">
        <form method="POST" action="{{ route('deploy.configuration.workflow', $project) }}" class="grid gap-3 p-4 sm:p-6">
            @csrf
            <x-signal.ui.textarea-field name="workflow" :label="__('Workflow YAML')" rows="10" class="font-mono" :value="old('workflow', $project->workflow_document ?? '')" placeholder="version: 1&#10;environments:&#10;  production:&#10;    deployment: { cron: '0 3 * * *', timezone: UTC }" />
            <div><x-signal.ui.button type="submit" variant="secondary">{{ __('Apply workflow') }}</x-signal.ui.button></div>
        </form>
    </x-signal.ui.settings-section>
    @endcan
</x-signal.layouts.project>
