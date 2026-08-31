<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        :title="__('Manage Repositories')"
        :description="__('Easily manage your repositories')"
    >
        <x-slot:buttons>
            <a
                href="{{ route('repositories.create') }}"
                class="flex items-center bg-primary px-3 py-2 text-primary text-xs rounded border border-primary"
            >
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Repository') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <!--
     ! ------------------------------------------------------------
     ! List Repositorys
     ! ------------------------------------------------------------
     !-->
    @if(!$repositories->isEmpty())
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-primary border-t border-b border-primary">
                <thead class="bg-primary border-l border-r border-primary">
                    <tr>
                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-primary sm:pl-6">
                            {{ __('Repository') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Description') }}
                        </th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-primary bg-primary">
                    @foreach($repositories as $repository)
                        <tr class="border-l border-r border-primary">
                            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 flex-shrink-0">
                                        <x-avatar :name="$repository->name" class="h-10 w-10 rounded-md text-sm" />
                                    </div>
                                    <a href="{{ route('repositories.show', $repository) }}" class="ml-4">
                                        <div class="font-medium text-primary">
                                            {{ $repository->name }}
                                        </div>
                                        <div class="text-secondary">
                                            {{ $repository->url }}
                                        </div>
                                    </a>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                <div class="text-primary">
                                    {{ $repository->description }}
    s                            </div>
                            </td>
                            <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                                    <use xlink:href="/assets/images/icons.svg#chevron-right"></use>
                                </svg>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="max-w-3xl mx-auto">
            <x-lists.empty
                title="{{ __('You have no repositories') }}"
                description="{{ __('You have no repositories. Click the button below to add one.') }}"
            >
                <x-slot:button>
                    <a href="{{ route('repositories.create') }}" class="px-3 py-2 bg-secondary border border-primary text-primary rounded text-sm shadow">
                        {{ __('Add Repository') }}
                    </a>
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif
</x-layouts.app>
