<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        :title="__('Servers')"
        :description="__('Manage cloud capacity and review filtered provisioning state.')"
    >
        <x-slot:buttons>
            <a href="{{ route('servers.import.create') }}" class="flex items-center bg-primary px-3 py-2 text-primary text-xs rounded-sm border border-primary">
                {{ __('Import existing') }}
            </a>
            <a
                href="{{ route('servers.create') }}"
                class="flex items-center bg-primary px-3 py-2 text-primary text-xs rounded-sm border border-primary"
            >
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Server') }}
            </a>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    <form method="GET" action="{{ route('servers.index') }}" class="mt-8 rounded-lg border border-primary bg-primary p-4">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div>
                <label for="search" class="block text-xs font-semibold uppercase text-secondary">{{ __('Search') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['search'] }}"
                    placeholder="{{ __('Name, identifier, or IP address') }}"
                    class="input secondary mt-1 w-full rounded-sm"
                >
            </div>
            <div>
                <label for="status" class="block text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</label>
                <select id="status" name="status" class="input secondary mt-1 w-full rounded-sm">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>
                            {{ str($status)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <label class="flex min-h-[42px] w-full items-center gap-2 rounded-sm border border-primary px-3 text-sm text-primary">
                    <input type="checkbox" name="provisioning" value="1" @checked($filters['provisioning'])>
                    {{ __('Provisioning only') }}
                </label>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <button type="submit" class="button primary">{{ __('Apply filters') }}</button>
            <a href="{{ route('servers.export', array_filter($filters, fn ($value) => $value !== null)) }}" class="button primary">
                {{ __('Export CSV') }}
            </a>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a href="{{ route('servers.index') }}" class="button primary">{{ __('Clear filters') }}</a>
            @endif
        </div>
    </form>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Matching servers') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['total'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Servers in this filtered view.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Ready servers') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['ready'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Active servers ready for workloads.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Provisioning') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['provisioning'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Queued, awaiting an IP, or provisioning.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Failed servers') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['failed'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Matching provisioning failures.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Hosted websites') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-primary">{{ $metrics['websites'] }}</dd>
            <dd class="mt-1 text-xs text-secondary">{{ __('Websites attached to matching servers.') }}</dd>
        </div>
        <div class="rounded-lg border border-primary bg-primary p-4">
            <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Latest matching server') }}</dt>
            <dd class="mt-1 text-lg font-bold text-primary">{{ $metrics['latest_at']?->diffForHumans() ?? __('Not available') }}</dd>
            <dd class="mt-1 text-xs text-secondary">
                {{ $metrics['latest_at']?->toDayDateTimeString() ?? __('No matching server recorded.') }}
            </dd>
        </div>
    </dl>

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
                                    <div class="h-10 w-10 shrink-0">
                                        <x-avatar :name="$server->label" class="h-10 w-10 rounded-md text-sm" />
                                    </div>
                                    <a href="{{ route('servers.show', $server) }}" class="ml-4">
                                        <div class="font-medium text-ternary">
                                            {{ $server->label }}
                                        </div>
                                        <div class="text-secondary">
                                            @if (filled($server->display_name))
                                                {{ $server->name }} &middot;
                                            @endif
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
                                <a href="{{ route('servers.show', $server) }}" aria-label="{{ __('View :name', ['name' => $server->label]) }}">
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
                        <a href="{{ route('servers.create') }}" class="px-3 py-2 bg-secondary border border-primary text-primary rounded-sm text-sm shadow-sm">
                            {{ __('Add Server') }}
                        </a>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif
</x-layouts.app>
