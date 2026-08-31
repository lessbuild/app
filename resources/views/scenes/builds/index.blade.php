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

    <!--
     ! ------------------------------------------------------------
     ! List Builds
     ! ------------------------------------------------------------
     !-->
    @if(!$builds->isEmpty())
        <table class="mt-10 min-w-full divide-y divide-primary border-t border-b border-primary">
            <thead class="bg-primary border-l border-r border-primary">
                <tr>
                    <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-primary sm:pl-6">
                        {{ __('Server') }}
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
                title="{{ __('You have no builds') }}"
                description="{{ __('You have no recent builds') }}"
            ></x-lists.empty>
        </div>
    @endif
</x-layouts.app>
