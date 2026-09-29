<x-signal.layouts.app :title="__('Projects')">
    <x-signal.ui.page-header :eyebrow="$account?->name" :title="__('Projects')" :description="__('Each project groups the environments, domains and services of one app or site.')">
        @if ($canCreate && $projects !== [])
            <x-slot:actions>
                <x-signal.ui.button :href="route('projects.templates')" variant="secondary">{{ __('From a template') }}</x-signal.ui.button>
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
                    <x-signal.ui.button :href="route('projects.templates')" variant="secondary">{{ __('Start from a template') }}</x-signal.ui.button>
                    <form method="POST" action="{{ route('projects.sample') }}">@csrf<x-signal.ui.button type="submit" variant="secondary">{{ __('Explore a sample project') }}</x-signal.ui.button></form>
                    </div>
                </x-slot:action>
            @endif
        </x-signal.ui.empty-state>
    @else
        <div class="grid items-start gap-6 xl:grid-cols-3">
        <ul class="grid gap-4 sm:grid-cols-2 xl:col-span-2" aria-label="{{ __('Projects') }}">
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

        {{-- The team's recent deploys, incidents and changes; refreshes itself so a deploy's outcome appears as it lands. --}}
        <x-signal.ui.panel as="section" class="grid gap-4 p-5" aria-labelledby="activity-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="activity-heading" class="text-base font-extrabold text-ink">{{ __('Recent activity') }}</h2>
                <nav class="flex flex-wrap gap-1 text-xs font-bold" aria-label="{{ __('Filter activity') }}">
                    @foreach (['' => __('All')] + array_map(fn ($label) => __($label), \App\Queries\Dashboard\AccountActivityQuery::KINDS) as $key => $label)
                        <a href="{{ route('dashboard', $key === '' ? [] : ['activity' => $key]) }}" @class(['rounded-full px-2.5 py-1', 'bg-primary-soft text-primary' => ($activityKind ?? '') === $key, 'text-muted hover:text-ink' => ($activityKind ?? '') !== $key]) @if (($activityKind ?? '') === $key) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>
            </div>
            <div id="dashboard-activity" data-live-region data-live-interval="15000">
                @forelse ($activity as $item)
                    <div @class(['flex items-start gap-3 py-3 text-sm', 'border-t border-line' => ! $loop->first])>
                        <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-surface-muted text-muted" aria-hidden="true"><x-signal.ui.icon :name="$item->icon" class="size-4" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-ink">
                                @if ($item->url)<a href="{{ $item->url }}" class="hover:text-primary hover:underline">{{ $item->title }}</a>@else{{ $item->title }}@endif
                            </p>
                            <p class="mt-0.5 text-xs text-muted">
                                {{ collect([$item->project, $item->actor])->filter()->implode(' · ') }}@if ($item->project || $item->actor) · @endif<time datetime="{{ $item->at->toIso8601String() }}" title="{{ $item->at->toDayDateTimeString() }}">{{ $item->at->diffForHumans() }}</time>
                            </p>
                        </div>
                        <x-signal.ui.badge :tone="$item->tone">{{ $item->outcome }}</x-signal.ui.badge>
                    </div>
                @empty
                    <p class="py-3 text-sm text-muted">{{ __('Nothing yet. Deploys, incidents and changes across your projects show up here.') }}</p>
                @endforelse
            </div>
        </x-signal.ui.panel>
        </div>
    @endif
</x-signal.layouts.app>
