@php($project = $overview->project)

<x-signal.layouts.project :overview="$overview" :title="__('Repositories')" :description="__('Git repositories that deploy to your websites. Each deploy is a new release; the previous ones stay on the server for rollbacks.')">
    <div class="flex justify-end"><x-signal.ui.button :href="route('account.inventory', 'repositories')" variant="quiet" size="sm">{{ __('Export CSV') }}</x-signal.ui.button></div>
    @if ($canCreate)
        <div class="flex justify-end"><x-signal.ui.button :href="route('deploy.repositories.create', $project)" data-modal-trigger="connect-repository" :data-modal-history-url="route('deploy.repositories', [$project, 'dialog' => 'connect-repository'])" variant="primary">{{ __('Connect a repository') }}</x-signal.ui.button></div>
    @endif

    @if ($repositories->isEmpty())
        <x-signal.ui.empty-state icon="cloud-upload" :title="__('No repositories yet')" :description="__('Connect a GitHub, GitLab or Bitbucket repository to deploy it to one of your websites.')" />
    @else
        <x-signal.ui.table :caption="__('Repositories')">
            <x-slot:head><tr><th scope="col">{{ __('Repository') }}</th><th scope="col">{{ __('Deploys to') }}</th><th scope="col">{{ __('Last deploy') }}</th></tr></x-slot:head>
            @foreach ($repositories as $repository)
                @php($build = $latest->get($repository->id))
                <tr>
                    <td><a href="{{ route('deploy.repositories.show', [$project, $repository->id]) }}" class="font-bold text-primary hover:underline">{{ $repository->name }}</a> <span class="font-mono text-xs text-muted">{{ $repository->url }} · {{ $repository->branch }}</span></td>
                    <td>{{ $repository->website->name }}@if ($repository->environment) <span class="text-muted">· {{ $repository->environment->name }}</span>@endif</td>
                    <td>
                        @if ($build)
                            <a href="{{ route('deploy.builds.show', [$project, $build->id]) }}" class="inline-flex items-center gap-2">@include('deploy._build-status', ['status' => $build->status]) <span class="text-xs text-muted">{{ $build->created_at?->diffForHumans() }}</span></a>
                        @else
                            <span class="text-muted">{{ __('Never') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-signal.ui.table>
    @endif

    @if ($canCreate)
        <x-signal.overlays.page-modal id="connect-repository" :title="__('Connect a repository')" :src="route('deploy.repositories.create', $project)" size="large" />
    @endif
</x-signal.layouts.project>
