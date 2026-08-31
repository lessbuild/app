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

    <form method="GET" action="{{ route('servers.index') }}" class="mt-8 rounded-lg border border-primary bg-primary p-4">
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Name, identifier, or IP address') }}"
                    class="input secondary mt-1 w-full rounded"
                >
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
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="button primary">{{ __('Apply filters') }}</button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a href="{{ route('servers.index') }}" class="button primary">{{ __('Clear filters') }}</a>
            @endif
        </div>
    </form>

    <!--
     ! ------------------------------------------------------------
     ! List Servers
     ! ------------------------------------------------------------
     !-->
    @if(!$servers->isEmpty())
        <div class="mt-6 overflow-x-auto">
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
                                        <x-avatar :name="$server->name" class="h-10 w-10 rounded-md text-sm" />
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
                                    <span>{{ str($server->type->value)->replace('-', ' ')->title() }}</span>
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
        <div class="py-4">
            {{ $servers->links() }}
        </div>
    @else
        <div class="max-w-3xl mx-auto">
            <x-lists.empty
                :title="array_filter($filters, fn ($value) => $value !== null) ? __('No servers match these filters') : __('You have no servers')"
                :description="array_filter($filters, fn ($value) => $value !== null) ? __('Try changing or clearing the selected filters.') : __('You have no servers. Click the button below to add one.')"
            >
                <x-slot:button>
                    @if (array_filter($filters, fn ($value) => $value !== null))
                        <a href="{{ route('servers.index') }}" class="button primary">{{ __('Clear filters') }}</a>
                    @else
                        <a href="{{ route('servers.create') }}" class="px-3 py-2 bg-secondary border border-primary text-primary rounded text-sm shadow">
                            {{ __('Add Server') }}
                        </a>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif
</x-layouts.app>
