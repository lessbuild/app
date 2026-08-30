<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        :title="__('Manage Servers')"
        :description="__('Easily manage your servers')"
    >
        <x-slot:buttons>
            <a
                href="{{ route('servers.create') }}"
                class="flex items-center bg-primary px-3 py-2 text-primary text-xs rounded border border-primary"
            >
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Server') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <!--
     ! ------------------------------------------------------------
     ! List Servers
     ! ------------------------------------------------------------
     !-->
    @if(!$servers->isEmpty())
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-primary border-t border-b border-primary">
                <thead class="bg-primary border-l border-r border-primary">
                    <tr>
                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-primary sm:pl-6">
                            {{ __('Server') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Specifics') }}
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('IP') }}
                            <span class="text-xs text-secondary">
                                 (Public/Private)
                            </span>
                        </th>
                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-primary">
                            {{ __('Status') }}
                        </th>
                        <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-primary bg-primary">
                    @foreach($servers as $server)
                        <tr class="border-l border-r border-primary">
                            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 flex-shrink-0">
                                        <img class="h-10 w-10 rounded-md" src="https://ui-avatars.com/api/?name={{ $server->name }}&size=128&background=1e293b&color=fff" alt="">
                                    </div>
                                    <a href="{{ route('servers.show', $server) }}" class="ml-4">
                                        <div class="font-medium text-ternary">
                                            {{ $server->name }}
                                        </div>
                                        <div class="text-secondary">
                                            #{{ $server->identifier }}
                                        </div>
                                    </a>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                <div class="text-primary flex flex-col">
                                    <span>{{ $server->region }}</span>
                                    <span>{{ $server->image }}</span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                <div class="text-primary flex flex-col">
                                    <span>
                                        {{ $server->public_ip ?? 'Not generated yet' }}
                                    </span>
                                    <span>
                                        {{ $server->private_ip ?? 'Not generated yet' }}
                                    </span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-sm text-secondary">
                                <span @class([
                                    'rounded-full px-3 py-1 text-xs font-semibold uppercase',
                                    'bg-green-100 text-green-700' => $server->provisioning_status === \App\Models\Server::STATUS_ACTIVE,
                                    'bg-red-100 text-red-700' => $server->provisioning_status === \App\Models\Server::STATUS_FAILED,
                                    'bg-blue-100 text-blue-700' => ! in_array($server->provisioning_status, [\App\Models\Server::STATUS_ACTIVE, \App\Models\Server::STATUS_FAILED], true),
                                ])>{{ str($server->provisioning_status)->replace('_', ' ') }}</span>
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
                title="{{ __('You have no servers') }}"
                description="{{ __('You have no servers. Click the button below to add one.') }}"
            >
                <x-slot:button>
                    <a href="{{ route('servers.create') }}" class="px-3 py-2 bg-secondary border border-primary text-primary rounded text-sm shadow">
                        {{ __('Add Server') }}
                    </a>
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif
</x-layouts.app>
