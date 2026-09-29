<x-signal.layouts.app :title="__('Projects')">
    <x-signal.ui.page-header :eyebrow="$account?->name" :title="__('Projects')" :description="__('Each project groups the environments, domains and services of one app or site.')">
        @if ($canCreate && $projects !== [])
            <x-slot:actions>
                <x-signal.ui.button :href="route('projects.create')" variant="primary" data-modal-trigger="new-project" :data-modal-history-url="request()->fullUrlWithQuery(['dialog' => 'new-project'])">{{ __('New project') }}</x-signal.ui.button>
            </x-slot:actions>
        @endif
    </x-signal.ui.page-header>

    @if (session('status'))
        <x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>
    @endif

    @if ($account === null)
        <x-signal.ui.empty-state :title="__('You’re not in an account')" :description="__('Ask someone to invite you, or create an account.')" />
    @elseif ($projects === [])
        <x-signal.ui.empty-state :title="__('Create your first project')" :description="$canCreate ? __('A project is one app or site. You’ll add domains and turn on Deploy, Monitoring, Analytics or Infrastructure next.') : __('No projects yet. Someone who manages projects in :account can create one.', ['account' => $account->name])">
            @if ($canCreate)
                <x-slot:action>
                    <div class="flex flex-wrap justify-center gap-3">
                    <x-signal.ui.button :href="route('projects.create')" variant="primary" data-modal-trigger="new-project" :data-modal-history-url="request()->fullUrlWithQuery(['dialog' => 'new-project'])">{{ __('Create a project') }}</x-signal.ui.button>
                    <form method="POST" action="{{ route('projects.sample') }}">@csrf<x-signal.ui.button type="submit" variant="secondary">{{ __('Explore a sample project') }}</x-signal.ui.button></form>
                    </div>
                </x-slot:action>
            @endif
        </x-signal.ui.empty-state>
    @else
        <ul class="grid gap-4 sm:grid-cols-2" aria-label="{{ __('Projects') }}">
            @foreach ($projects as $project)
                <li>
                    <x-signal.ui.card as="a" tone="interactive" :href="route('projects.show', $project->id)" class="block h-full p-5">
                        <p class="text-base font-extrabold text-ink">{{ $project->name }}</p>
                        @if ($project->description)
                            <p class="mt-1 line-clamp-2 text-sm text-muted">{{ $project->description }}</p>
                        @endif
                        <div class="mt-4 flex flex-wrap items-center gap-1.5">
                            @forelse ($project->serviceNames as $name)
                                <x-signal.ui.badge tone="accent">{{ $name }}</x-signal.ui.badge>
                            @empty
                                <x-signal.ui.badge>{{ __('No services yet') }}</x-signal.ui.badge>
                            @endforelse
                            <span class="text-xs text-muted">· {{ trans_choice(':count environment|:count environments', $project->environmentCount, ['count' => $project->environmentCount]) }}</span>
                        </div>
                    </x-signal.ui.card>
                </li>
            @endforeach
        </ul>
    @endif
</x-signal.layouts.app>
