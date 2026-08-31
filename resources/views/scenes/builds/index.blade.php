<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        :title="__('View Builds')"
        :description="__('Easily view your recent builds')"
    >
    </x-layouts.partials.heading>

    <form method="GET" action="{{ route('builds.index') }}" class="mt-8 rounded-lg border border-primary bg-primary p-4">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div>
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Repository, revision, or commit') }}"
                    class="input secondary mt-1 w-full rounded"
                >
            </div>
            <div>
                <label for="repository_id" class="block text-xs font-semibold uppercase text-secondary">{{ __('Repository') }}</label>
                <select id="repository_id" name="repository_id" class="input secondary mt-1 w-full rounded">
                    <option value="">{{ __('All repositories') }}</option>
                    @foreach ($repositories as $repository)
                        <option value="{{ $repository->id }}" @selected((int) $filters['repository_id'] === $repository->id)>
                            {{ $repository->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="status" class="block text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</label>
                <select id="status" name="status" class="input secondary mt-1 w-full rounded">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>
                            {{ str($status)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="trigger" class="block text-xs font-semibold uppercase text-secondary">{{ __('Trigger') }}</label>
                <select id="trigger" name="trigger" class="input secondary mt-1 w-full rounded">
                    <option value="">{{ __('All triggers') }}</option>
                    @foreach ($triggers as $trigger)
                        <option value="{{ $trigger }}" @selected($filters['trigger'] === $trigger)>
                            {{ str($trigger)->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <label class="flex min-h-[42px] w-full items-center gap-2 rounded border border-primary px-3 text-sm text-primary">
                    <input type="checkbox" name="latest" value="1" @checked($filters['latest'])>
                    {{ __('Latest per repository only') }}
                </label>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="button primary">{{ __('Apply filters') }}</button>
            <a href="{{ route('builds.export', array_filter($filters, fn ($value) => $value !== null)) }}" class="button primary">
                {{ __('Export CSV') }}
            </a>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a href="{{ route('builds.index') }}" class="button primary">{{ __('Clear filters') }}</a>
            @endif
        </div>
    </form>

    <!--
     ! ------------------------------------------------------------
     ! List Builds
     ! ------------------------------------------------------------
     !-->
    @if(!$builds->isEmpty())
        <table class="mt-6 min-w-full divide-y divide-primary border-t border-b border-primary">
            <thead class="bg-primary border-l border-r border-primary">
                <tr>
                    <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-primary sm:pl-6">
                        {{ __('Repository') }}
                    </th>
                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                        {{ __('Status') }}
                    </th>
                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                        {{ __('Finished') }}
                    </th>
                    <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-primary bg-primary">
                @foreach($builds as $build)
                    <tr class="border-l border-r border-primary">
                        <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                            <div class="flex items-center">
                                <div class="h-10 w-10 flex-shrink-0">
                                    <img class="h-10 w-10 rounded-md" src="https://ui-avatars.com/api/?name={{ $build->repository->name }}&size=128&background=1e293b&color=fff" alt="">
                                </div>
                                <a href="{{ route('builds.show', $build) }}" class="ml-4">
                                    <div class="font-medium text-ternary">
                                        {{ $build->repository->name }}
                                    </div>
                                    <div class="text-secondary">
                                        #{{ $build->repository->website->server->name }}
                                    </div>
                                    <div class="text-secondary">
                                        {{ ucfirst($build->trigger_source) }}
                                        @if ($build->revision)
                                            &middot; <span class="font-mono">{{ $build->shortRevision() }}</span>
                                        @endif
                                    </div>
                                </a>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                            <span class="uppercase">{{ str($build->status)->replace('_', ' ') }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                            <div class="text-primary flex flex-col">
                                {{ $build->finished_at?->diffForHumans() ?? __('Not finished') }}
                            </div>
                        </td>
                        <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                            <a href="{{ route('builds.show', $build) }}" aria-label="{{ __('View build #:id', ['id' => $build->id]) }}">
                                <svg class="inline-block w-4 h-4 text-secondary stroke-2 mr-2">
                                    <use xlink:href="/assets/images/icons.svg#chevron-right"></use>
                                </svg>
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="py-4">
            {{ $builds->links() }}
        </div>
    @else
        <div class="max-w-3xl mx-auto">
            <x-lists.empty
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No builds match these filters') : __('You have no builds')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('You have no recent builds')"
            >
                @if (array_filter($filters, fn ($value) => $value !== null))
                    <x-slot:button>
                        <a href="{{ route('builds.index') }}" class="button primary">{{ __('Clear filters') }}</a>
                    </x-slot:button>
                @endif
            </x-lists.empty>
        </div>
    @endif
</x-layouts.app>
