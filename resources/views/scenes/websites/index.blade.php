<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        :title="__('Manage Websites')"
        :description="__('Easily manage your websites')"
    >
        <x-slot:buttons>
            <a
                href="{{ route('websites.create') }}"
                class="flex items-center bg-primary px-3 py-2 text-primary text-xs rounded border border-primary"
            >
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Website') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <!--
     ! ------------------------------------------------------------
     ! List Websites
     ! ------------------------------------------------------------
     !-->
    @if(!$websites->isEmpty())
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-primary border-t border-b border-primary">
                <thead class="bg-primary border-l border-r border-primary">
                    <tr>
                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-primary sm:pl-6">
                            {{ __('Website') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Server') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Description') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Added') }}
                        </th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-primary bg-primary">
                    @foreach($websites as $website)
                        <tr class="border-l border-r border-primary">
                            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 flex-shrink-0">
                                        <img class="h-10 w-10 rounded-md" src="https://ui-avatars.com/api/?name={{ $website->name }}&size=128&background=1e293b&color=fff" alt="">
                                    </div>
                                    <a href="{{ route('websites.show', $website) }}" class="ml-4">
                                        <div class="font-medium text-ternary">
                                            {{ $website->name }}
                                        </div>
                                        <div class="text-secondary">
                                            {{ $website->url }}
                                        </div>
                                    </a>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm">
                                <a href="{{ route('servers.show', $website->server) }}" class="text-ternary cursor-pointer">
                                    {{ $website->server->name }}
                                </a>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                <div class="text-primary">
                                    {{ $website->description }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                <div class="text-primary">
                                    {{ $website->created_at->diffForHumans() }}
                                </div>
                            </td>
                            <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                <a href="{{ route('websites.show', $website) }}">
                                    <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                                        <use xlink:href="/assets/images/icons.svg#chevron-right"></use>
                                    </svg>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="max-w-3xl mx-auto">
            <x-lists.empty
                title="{{ __('You have no websites') }}"
                description="{{ __('You have no websites. Click the button below to add one.') }}"
            >
                <x-slot:button>
                    <a href="{{ route('websites.create') }}" class="button secondary">
                        <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                            <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                        </svg>
                        {{ __('Add Website') }}
                    </a>
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif
</x-layouts.app>
