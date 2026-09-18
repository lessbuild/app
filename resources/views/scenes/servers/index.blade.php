<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        eyebrow="{{ __('Infrastructure') }}"
        icon="server"
        :title="__('Servers')"
        :description="__('Manage cloud capacity and review filtered provisioning state.')"
    >
        <x-slot:buttons>
            <x-ui.button :href="route('servers.import.create')" variant="secondary">{{ __('Import existing') }}</x-ui.button>
            <x-ui.button :href="route('servers.create')" variant="primary">
                <svg class="mr-2 h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Server') }}
            </x-ui.button>
        </x-slot:buttons>
    </x-layouts.partials.heading>

    @php($activeFilterCount = count(array_filter($filters, fn ($value) => $value !== null)))

    <x-ui.filter-panel
        id="servers-filters"
        class="mt-8"
        :label="__('Filter servers')"
        :open="$activeFilterCount > 0"
        :summary="$activeFilterCount > 0 ? __(':count active', ['count' => $activeFilterCount]) : null"
    >
        <form method="GET" action="{{ route('servers.index') }}">
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
                    class="input secondary mt-1 w-full rounded-lg"
                >
            </div>
            <div>
                <label for="status" class="block text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</label>
                <select id="status" name="status" class="input secondary mt-1 w-full rounded-lg">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>
                            {{ str($status)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <label class="flex min-h-[42px] w-full items-center gap-2 rounded-lg border border-primary px-3 text-sm text-primary">
                    <input type="checkbox" name="provisioning" value="1" @checked($filters['provisioning'])>
                    {{ __('Provisioning only') }}
                </label>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-ui.button>
            <x-ui.button :href="route('servers.export', array_filter($filters, fn ($value) => $value !== null))" variant="secondary">
                {{ __('Export CSV') }}
            </x-ui.button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <x-ui.button :href="route('servers.index')" variant="ghost">{{ __('Clear filters') }}</x-ui.button>
            @endif
        </div>
        </form>
    </x-ui.filter-panel>

    <x-ui.insights
        id="servers-insights"
        class="mt-6"
        :summary="trans_choice(':count matching server|:count matching servers', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-ui.stat :label="__('Matching servers')" :value="$metrics['total']" :description="__('Servers in this filtered view.')" />
            <x-ui.stat :label="__('Ready servers')" :value="$metrics['ready']" :description="__('Active servers ready for workloads.')" />
            <x-ui.stat :label="__('Provisioning')" :value="$metrics['provisioning']" :description="__('Queued, awaiting an IP, or provisioning.')" />
            <x-ui.stat :label="__('Failed servers')" :value="$metrics['failed']" :description="__('Matching provisioning failures.')" />
            <x-ui.stat :label="__('Hosted websites')" :value="$metrics['websites']" :description="__('Websites attached to matching servers.')" />
            <x-ui.stat :label="__('Latest matching server')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="$metrics['latest_at']?->toDayDateTimeString() ?? __('No matching server recorded.')" />
        </dl>
    </x-ui.insights>

    <!--
     ! ------------------------------------------------------------
    ! List Servers
     ! ------------------------------------------------------------
     !-->
    @if(!$servers->isEmpty())
        <div class="ui-card ui-inventory-list mt-6 divide-y divide-primary overflow-hidden" aria-label="{{ __('Server inventory') }}">
            @foreach($servers as $server)
                <article data-server-card class="p-4 sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-avatar :name="$server->label" class="h-10 w-10 shrink-0 rounded-md text-sm" />
                            <div class="min-w-0">
                                <a href="{{ route('servers.show', $server) }}" class="font-semibold text-primary hover:underline">{{ $server->label }}</a>
                                <p class="text-sm text-secondary">
                                    @if (filled($server->display_name))
                                        {{ $server->name }} &middot;
                                    @endif
                                    #{{ $server->identifier }}
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($server->provisioning_status === \App\Models\Server::STATUS_ACTIVE)
                                <x-ui.badge tone="success">{{ str($server->provisioning_status)->replace('_', ' ') }}</x-ui.badge>
                            @elseif ($server->provisioning_status === \App\Models\Server::STATUS_FAILED)
                                <x-ui.badge tone="danger">{{ str($server->provisioning_status)->replace('_', ' ') }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="accent">{{ str($server->provisioning_status)->replace('_', ' ') }}</x-ui.badge>
                            @endif
                            <x-ui.button :href="route('servers.show', $server)" variant="secondary">{{ __('View server') }}</x-ui.button>
                        </div>
                    </div>

                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Specifics') }}</dt>
                            <dd class="mt-1 text-primary">
                                {{ $server->region }}
                                <span class="mt-1 block text-secondary">{{ $server->image }}</span>
                                <span class="mt-1 block text-secondary">{{ str($server->type->value)->replace('-', ' ')->title() }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Public IP') }}</dt>
                            <dd class="mt-1 font-mono text-xs text-primary">{{ $server->public_ip ?? __('Not generated yet') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Private IP') }}</dt>
                            <dd class="mt-1 font-mono text-xs text-primary">{{ $server->private_ip ?? __('Not generated yet') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Status') }}</dt>
                            <dd class="mt-1 text-primary">{{ str($server->provisioning_status)->replace('_', ' ')->title() }}</dd>
                        </div>
                    </dl>
                </article>
            @endforeach
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
                        <x-ui.button :href="route('servers.index')" variant="primary">{{ __('Clear filters') }}</x-ui.button>
                    @else
                        <x-ui.button :href="route('servers.create')" variant="secondary">{{ __('Add Server') }}</x-ui.button>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif
</x-layouts.app>
