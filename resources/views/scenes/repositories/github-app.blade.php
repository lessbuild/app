<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('repositories.index')" :title="__('Back to repositories')" />
    <x-layouts.partials.heading :title="__('GitHub repositories')" :description="__('Repositories accessible to :provider. Access is refreshed with a short-lived installation token.', ['provider' => $provider->name])" />
    <div class="mt-8 grid gap-3 md:grid-cols-2">
        @forelse($repositories as $repository)
            <article class="flex items-center gap-4 rounded-xl border border-primary bg-primary p-4"><div class="min-w-0 flex-1"><h2 class="truncate font-bold text-primary">{{ $repository['full_name'] }}</h2><p class="mt-1 text-xs text-secondary">{{ $repository['private'] ? __('Private') : __('Public') }} · {{ $repository['default_branch'] }}</p></div><a href="{{ route('repositories.create', ['provider_id' => $provider->id, 'name' => str($repository['full_name'])->after('/'), 'url' => 'github.com/'.$repository['full_name'].'.git', 'branch' => $repository['default_branch']]) }}" class="button primary">{{ __('Connect') }}</a></article>
        @empty
            <x-lists.empty :title="__('No repositories available')" :description="__('Update the GitHub App installation and grant access to at least one repository.')" />
        @endforelse
    </div>
</x-layouts.app>
