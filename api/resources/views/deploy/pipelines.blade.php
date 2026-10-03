@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Pipelines')" :description="__('Deploy several repositories in order, such as the API and then the frontend. Each waits for the one before to go live, and the run stops if one fails.')">
    @forelse ($pipelines as $pipeline)
        <x-signal.ui.card as="section" class="grid gap-3 p-5 sm:p-6" aria-labelledby="pipeline-{{ $pipeline->id }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="pipeline-{{ $pipeline->id }}" class="text-lg font-extrabold text-ink">{{ $pipeline->name }}</h2>
                <div class="flex gap-2">
                    <form method="POST" action="{{ route('deploy.pipelines.run', [$project, $pipeline->id]) }}">@csrf<x-signal.ui.button type="submit" variant="primary" size="sm">{{ __('Run') }}</x-signal.ui.button></form>
                    @if ($canManage)
                        <form method="POST" action="{{ route('deploy.pipelines.destroy', [$project, $pipeline->id]) }}">@csrf @method('DELETE')<x-signal.ui.button type="submit" variant="quiet" size="sm">{{ __('Delete') }}</x-signal.ui.button></form>
                    @endif
                </div>
            </div>
            <ol class="flex flex-wrap items-center gap-2 text-sm">
                @foreach ($pipeline->repository_ids as $index => $repositoryId)
                    <li class="flex items-center gap-2">
                        @if ($index > 0)<span aria-hidden="true" class="text-muted">→</span>@endif
                        <span class="rounded-control border border-line px-2 py-1">{{ $repositories->get($repositoryId)?->name ?? __('Removed repository') }} <span class="text-xs text-muted">{{ $repositories->get($repositoryId)?->environment?->name }}</span></span>
                    </li>
                @endforeach
            </ol>
            @if ($pipeline->runs->isNotEmpty())
                <ul class="grid gap-1 text-sm" aria-label="{{ __('Recent runs') }}">
                    @foreach ($pipeline->runs as $run)
                        <li class="flex flex-wrap items-center gap-2">
                            <x-signal.ui.badge :tone="match ($run->status) { 'succeeded' => 'success', 'failed' => 'danger', default => 'info' }">{{ __(ucfirst($run->status)) }}</x-signal.ui.badge>
                            <span class="text-muted">{{ $run->created_at?->diffForHumans() }}</span>
                            @foreach ($run->build_ids as $buildId)
                                <a href="{{ route('deploy.builds.show', [$project, $buildId]) }}" class="font-mono text-xs text-primary hover:underline">#{{ $buildId }} {{ $builds->get($buildId)?->status }}</a>
                            @endforeach
                            @if ($run->failure)<span class="text-xs text-danger">{{ $run->failure }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-signal.ui.card>
    @empty
        <x-signal.ui.empty-state icon="view-grid" :title="__('No pipelines yet')" :description="__('Connect two or more repositories, then chain them here.')" />
    @endforelse

    @if ($canManage && $repositories->count() >= 2)
        <x-signal.ui.settings-section :title="__('New pipeline')" :description="__('Pick the repositories in the order they should deploy. Each deploys its branch’s latest commit.')">
            <form method="POST" action="{{ route('deploy.pipelines.store', $project) }}" class="grid gap-3 p-4 sm:grid-cols-2 sm:p-6">
                @csrf
                <div class="sm:col-span-2"><x-signal.ui.input-field name="name" :label="__('Name')" maxlength="80" required placeholder="API then web" /></div>
                @foreach (range(1, min(4, $repositories->count())) as $step)
                    <x-signal.ui.select-field :name="'steps['.($step - 1).']'" :id="'pipeline-step-'.$step" :label="__('Step :step', ['step' => $step])">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($repositories as $repository)
                            <option value="{{ $repository->id }}">{{ $repository->name }} · {{ $repository->environment?->name }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                @endforeach
                <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="secondary">{{ __('Save pipeline') }}</x-signal.ui.button></div>
            </form>
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.project>
