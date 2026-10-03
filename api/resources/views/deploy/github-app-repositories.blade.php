<x-signal.layouts.account :account="$account" :title="$provider->name" :description="__('Repositories this GitHub App installation can reach. Connect one in a project to deploy it.')">
    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif
    @if ($repositories === [])
        <x-signal.ui.empty-state icon="cloud-upload" :title="__('No repositories yet')" :description="__('Give the installation access to repositories in your GitHub settings.')" />
    @else
        <x-signal.ui.card class="overflow-hidden">
            <ul class="divide-y divide-line">
                @foreach ($repositories as $repository)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <span class="font-mono text-sm">{{ $repository['full_name'] }} <span class="text-xs text-muted">{{ $repository['private'] ? __('private') : __('public') }} · {{ $repository['default_branch'] }}</span></span>
                        <span class="flex flex-wrap gap-1">
                            @foreach ($projects as $project)
                                <x-signal.ui.button size="sm" variant="quiet" :href="route('deploy.repositories.create', [$project, 'provider_id' => $provider->id, 'url' => 'github.com/'.strtolower($repository['full_name']), 'branch' => $repository['default_branch']])">{{ __('Connect in :project', ['project' => $project->name]) }}</x-signal.ui.button>
                            @endforeach
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-signal.ui.card>
    @endif
</x-signal.layouts.account>
