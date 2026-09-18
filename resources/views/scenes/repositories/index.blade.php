<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        icon="code"
        :title="__('Repositories')"
        :description="__('Manage source targets and review their latest filtered deployment state.')"
    >
        <x-slot:buttons>
            <x-ui.button :href="route('repositories.impact-preview')" variant="secondary">
                {{ __('Preview push impact') }}
            </x-ui.button>
            <x-ui.button :href="route('repositories.create')" variant="primary">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Repository') }}
            </x-ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <x-ui.card class="mt-8 p-4">
        <form method="GET" action="{{ route('repositories.index') }}">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Name, URL, or description') }}"
                    class="input secondary mt-1 w-full rounded-lg"
                >
            </div>
            <div>
                <label for="provider_id" class="block text-xs font-semibold uppercase text-secondary">{{ __('Provider') }}</label>
                <select id="provider_id" name="provider_id" class="input secondary mt-1 w-full rounded-lg">
                    <option value="">{{ __('All providers') }}</option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider->id }}" @selected((int) $filters['provider_id'] === $provider->id)>
                            {{ $provider->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="website_id" class="block text-xs font-semibold uppercase text-secondary">{{ __('Website') }}</label>
                <select id="website_id" name="website_id" class="input secondary mt-1 w-full rounded-lg">
                    <option value="">{{ __('All websites') }}</option>
                    @foreach ($websites as $website)
                        <option value="{{ $website->id }}" @selected((int) $filters['website_id'] === $website->id)>
                            {{ $website->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="status" class="block text-xs font-semibold uppercase text-secondary">{{ __('Latest deployment') }}</label>
                <select id="status" name="status" class="input secondary mt-1 w-full rounded-lg">
                    <option value="">{{ __('All deployment states') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>
                            {{ $status === 'none' ? __('Never deployed') : str($status)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-ui.button>
            <x-ui.button :href="route('repositories.export', array_filter($filters, fn ($value) => $value !== null))" variant="secondary">
                {{ __('Export CSV') }}
            </x-ui.button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <x-ui.button :href="route('repositories.index')" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
            @endif
        </div>
        </form>
    </x-ui.card>

    <x-ui.insights
        id="repositories-insights"
        class="mt-6"
        :summary="trans_choice(':count matching repository|:count matching repositories', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-ui.stat :label="__('Matching repositories')" :value="$metrics['total']" :description="__('Repositories in this filtered view.')" />
            <x-ui.stat :label="__('Never deployed')" :value="$metrics['never_deployed']" :description="__('Matching repositories without a build.')" />
            <x-ui.stat :label="__('Active deployments')" :value="$metrics['active']" :description="__('Latest deployment is still active.')" />
            <x-ui.stat :label="__('Latest succeeded')" :value="$metrics['succeeded']" :description="__('Latest deployment completed successfully.')" />
            <x-ui.stat :label="__('Latest failed')" :value="$metrics['failed']" :description="__('Latest deployment failed.')" />
            <x-ui.stat :label="__('Push webhooks')" :value="$metrics['webhooks']" :description="__('Matching repositories with webhooks enabled.')" />
        </dl>
    </x-ui.insights>

    <!--
     ! ------------------------------------------------------------
    ! List Repositories
     ! ------------------------------------------------------------
     !-->
    @if(!$repositories->isEmpty())
        <div class="ui-card mt-6 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-primary border-t border-b border-primary">
                <thead class="bg-primary border-l border-r border-primary">
                    <tr>
                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-primary sm:pl-6">
                            {{ __('Repository') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Deployment target') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Provider') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Latest deployment') }}
                        </th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-primary bg-primary">
                    @foreach($repositories as $repository)
                        <tr class="border-l border-r border-primary">
                            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 shrink-0">
                                        <x-avatar :name="$repository->name" class="h-10 w-10 rounded-md text-sm" />
                                    </div>
                                    <a href="{{ route('repositories.show', $repository) }}" class="ml-4">
                                        <div class="font-medium text-ternary">
                                            {{ $repository->name }}
                                        </div>
                                        <div class="text-secondary">
                                            {{ $repository->url }}
                                        </div>
                                        @if ($repository->description)
                                            <div class="max-w-md truncate text-secondary">
                                                {{ $repository->description }}
                                            </div>
                                        @endif
                                    </a>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                @if ($repository->website && ! $repository->website->trashed())
                                    <a href="{{ route('websites.show', $repository->website) }}" class="font-medium text-ternary">
                                        {{ $repository->website->name }}
                                    </a>
                                    <div>{{ $repository->website->server?->label ?? __('Server unavailable') }}</div>
                                @elseif ($repository->website)
                                    <span class="font-medium text-secondary">{{ __('Deleted website') }}</span>
                                    <div>{{ $repository->website->name }}</div>
                                @else
                                    {{ __('Website unavailable') }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                {{ $repository->provider?->name ?? __('Provider unavailable') }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                @if ($repository->latestBuild)
                                    <a href="{{ route('builds.show', $repository->latestBuild) }}" @class([
                                        'font-semibold uppercase',
                                        'text-green-600' => $repository->latestBuild->status === \App\Models\Build::STATUS_SUCCEEDED,
                                        'text-red-600' => $repository->latestBuild->status === \App\Models\Build::STATUS_FAILED,
                                        'text-secondary' => ! in_array($repository->latestBuild->status, [\App\Models\Build::STATUS_SUCCEEDED, \App\Models\Build::STATUS_FAILED], true),
                                    ])>
                                        {{ str($repository->latestBuild->status)->replace('_', ' ') }}
                                    </a>
                                    <div>{{ $repository->latestBuild->created_at->diffForHumans() }}</div>
                                @else
                                    {{ __('Never deployed') }}
                                @endif
                            </td>
                            <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                <a href="{{ route('repositories.show', $repository) }}" aria-label="{{ __('View :name', ['name' => $repository->name]) }}">
                                    <svg class="inline-block w-4 h-4 text-secondary stroke-2 mr-2">
                                        <use xlink:href="/assets/images/icons.svg#chevron-right"></use>
                                    </svg>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
        <div class="py-4">
            {{ $repositories->links() }}
        </div>
    @else
        <div class="max-w-3xl mx-auto">
            <x-ui.empty-state
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No repositories match these filters') : __('You have no repositories')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('You have no repositories. Click the button below to add one.')"
            >
                <x-slot:action>
                    @if (array_filter($filters, fn ($value) => $value !== null))
                        <x-ui.button :href="route('repositories.index')" variant="secondary">{{ __('Clear filters') }}</x-ui.button>
                    @else
                        <x-ui.button :href="route('repositories.create')" variant="primary">
                            {{ __('Add Repository') }}
                        </x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        </div>
    @endif
</x-layouts.app>
