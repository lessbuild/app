<x-layouts.app>
    @php
        $githubRepositoriesPageUrl = request()->fullUrlWithoutQuery('dialog');
        $repositoryCreateParameters = [
            'dialog' => 'create-repository',
            'provider_id' => $provider->id,
        ];
        $repositoryCreateUrl = (string) \Illuminate\Support\Uri::of($githubRepositoriesPageUrl)->withQuery([
            ...$repositoryCreateParameters,
            'name' => str($repository['full_name'])->after('/'),
            'url' => 'github.com/'.$repository['full_name'].'.git',
            'branch' => $repository['default_branch'],
        ]);
        $repositoryCreateContentUrl = route('dialogs.create', [
            'resource' => 'repository',
            'return_to' => $githubRepositoriesPageUrl,
            ...$repositoryCreateParameters,
            'name' => str($repository['full_name'])->after('/'),
            'url' => 'github.com/'.$repository['full_name'].'.git',
            'branch' => $repository['default_branch'],
        ]);
        $repositoryCreateOpen = request()->query('dialog') === 'create-repository';
    @endphp
    <x-layouts.partials.breadcrumbs :route="route('repositories.index')" :title="__('Back to repositories')" />
    <x-layouts.partials.heading icon="github" :title="__('GitHub repositories')" :description="__('Repositories accessible to :provider. Access is refreshed with a short-lived installation token.', ['provider' => $provider->name])" />
    <div class="mt-8 grid gap-3 md:grid-cols-2">
        @forelse($repositories as $repository)
            <x-ui.card tone="interactive" class="flex items-center gap-4 p-4">
                <div class="min-w-0 flex-1">
                    <h2 class="truncate font-bold text-ink">{{ $repository['full_name'] }}</h2>
                    <p class="mt-1 text-xs text-muted">{{ $repository['private'] ? __('Private') : __('Public') }} · {{ $repository['default_branch'] }}</p>
                </div>
                <x-ui.button :href="$repositoryCreateUrl" data-modal-trigger="repository-create-dialog" data-modal-content-url="{{ $repositoryCreateContentUrl }}" aria-controls="repository-create-dialog" aria-expanded="{{ $repositoryCreateOpen ? 'true' : 'false' }}" variant="primary">{{ __('Connect') }}</x-ui.button>
            </x-ui.card>
        @empty
            <x-ui.empty-state class="md:col-span-2" :title="__('No repositories available')" :description="__('Update the GitHub App installation and grant access to at least one repository.')" />
        @endforelse
    </div>
</x-layouts.app>
