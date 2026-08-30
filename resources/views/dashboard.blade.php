<x-layouts.app>
    <x-layouts.partials.heading
        :title="__('Dashboard')"
        :description="__('A quick view of your infrastructure and latest deployments.')"
    >
        <x-slot:buttons>
            <a href="{{ route('servers.create') }}" class="button primary">
                {{ __('Create server') }}
            </a>
            <a href="{{ route('websites.create') }}" class="button primary">
                {{ __('Add website') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <div class="grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 -mx-3 mb-12">
        <x-panel.stats icon="link" :title="$stats['websites']" :description="__('Websites')" />
        <x-panel.stats icon="cloud" :title="$stats['servers']" :description="__('Servers')" />
        <x-panel.stats icon="cloud-upload" :title="$stats['builds']" :description="__('Builds')" />
        <x-panel.stats icon="code" :title="$stats['repositories']" :description="__('Repositories')" />
    </div>

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
        <section>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-xl font-semibold text-primary">{{ __('Recent websites') }}</h2>
                <a href="{{ route('websites.index') }}" class="text-sm text-ternary">{{ __('View all') }}</a>
            </div>

            @forelse ($recentWebsites as $website)
                <a href="{{ route('websites.show', $website) }}" class="mb-3 flex items-center justify-between rounded-lg border border-primary bg-primary p-4">
                    <div>
                        <p class="font-medium text-primary">{{ $website->name }}</p>
                        <p class="text-sm text-secondary">{{ $website->url }}</p>
                    </div>
                    <span class="text-sm text-secondary">{{ $website->server?->name ?? __('No server') }}</span>
                </a>
            @empty
                <x-lists.empty
                    :title="__('No websites yet')"
                    :description="__('Create a website to begin configuring deployments.')"
                >
                    <x-slot:button>
                        <a href="{{ route('websites.create') }}" class="button primary">{{ __('Add website') }}</a>
                    </x-slot:button>
                </x-lists.empty>
            @endforelse
        </section>

        <section>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-xl font-semibold text-primary">{{ __('Recent builds') }}</h2>
                <a href="{{ route('builds.index') }}" class="text-sm text-ternary">{{ __('View all') }}</a>
            </div>

            @forelse ($recentBuilds as $build)
                <a href="{{ route('builds.show', $build) }}" class="mb-3 flex items-center justify-between rounded-lg border border-primary bg-primary p-4">
                    <div>
                        <p class="font-medium text-primary">{{ $build->repository->name }}</p>
                        <p class="text-sm text-secondary">{{ $build->repository->website?->name }}</p>
                    </div>
                    <div class="text-right text-sm text-secondary">
                        <span class="block uppercase">{{ $build->status }}</span>
                        <span>{{ ($build->built_at ?? $build->created_at)->diffForHumans() }}</span>
                    </div>
                </a>
            @empty
                <x-lists.empty
                    :title="__('No builds yet')"
                    :description="__('Your latest repository deployments will appear here.')"
                />
            @endforelse
        </section>
    </div>

    <section class="mt-12">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-primary">{{ __('Recent activity') }}</h2>
            <a href="{{ route('activity.index') }}" class="text-sm text-ternary">{{ __('View all') }}</a>
        </div>

        <x-activity-feed :events="$recentEvents" />
    </section>
</x-layouts.app>
