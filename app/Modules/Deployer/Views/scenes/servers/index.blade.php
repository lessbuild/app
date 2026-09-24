<x-layouts.app>

    @php
        $serverCreateOpen = request()->query('dialog') === 'create-server';
        $serverIndexQuery = array_filter($filters, fn ($value) => $value !== null);
        $serverCreateUrl = route('servers.index', [...$serverIndexQuery, 'dialog' => 'create-server']);
    @endphp

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-signal.ui.page-header
        eyebrow="{{ __('Infrastructure') }}"
        icon="server"
        :title="__('Servers')"
        :description="__('Manage cloud capacity and review filtered provisioning state.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('servers.import.create')" variant="secondary">{{ __('Import existing') }}</x-signal.ui.button>
            <x-signal.ui.button
                :href="$serverCreateUrl"
                data-modal-trigger="server-create-dialog"
                aria-controls="server-create-dialog"
                aria-expanded="{{ $serverCreateOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                <svg class="mr-2 h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#plus-circle"></use>
                </svg>
                {{ __('Add Server') }}
            </x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    @php($activeFilterCount = count(array_filter($filters, fn ($value) => $value !== null)))

    <x-signal.ui.filter-panel
        id="servers-filters"
        class="mt-8"
        :label="__('Filter servers')"
        :open="$activeFilterCount > 0"
        :summary="$activeFilterCount > 0 ? __(':count active', ['count' => $activeFilterCount]) : null"
    >
        <form method="GET" action="{{ route('servers.index') }}">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div>
                <x-signal.ui.input-field
                    id="search"
                    name="search"
                    :label="__('Search')"
                    type="search"
                    maxlength="100"
                    :value="$filters['search']"
                    :placeholder="__('Name, identifier, or IP address')"
                    :error-key="false"
                />
            </div>
            <div>
                <x-signal.ui.select-field id="status" name="status" :label="__('Status')" :error-key="false">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>
                            {{ str($status)->replace('_', ' ')->title() }}
                        </option>
                    @endforeach
                </x-signal.ui.select-field>
            </div>
            <div class="flex items-end">
                <x-signal.ui.checkbox id="provisioning" name="provisioning" :value="1" :checked="$filters['provisioning']" :restore="false" :error-key="false" container-class="h-full content-end">
                    {{ __('Provisioning only') }}
                </x-signal.ui.checkbox>
            </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Apply filters') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('servers.export', array_filter($filters, fn ($value) => $value !== null))" variant="secondary">
                {{ __('Export CSV') }}
            </x-signal.ui.button>
            @if (array_filter($filters, fn ($value) => $value !== null))
                <x-signal.ui.button :href="route('servers.index')" variant="ghost">{{ __('Clear filters') }}</x-signal.ui.button>
            @endif
        </div>
        </form>
    </x-signal.ui.filter-panel>

    <x-signal.ui.insights
        id="servers-insights"
        class="mt-6"
        :summary="trans_choice(':count matching server|:count matching servers', $metrics['total'], ['count' => $metrics['total']])"
    >
        <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <x-signal.ui.stat :label="__('Matching servers')" :value="$metrics['total']" :description="__('Servers in this filtered view.')" />
            <x-signal.ui.stat :label="__('Ready servers')" :value="$metrics['ready']" :description="__('Active servers ready for workloads.')" />
            <x-signal.ui.stat :label="__('Provisioning')" :value="$metrics['provisioning']" :description="__('Queued, awaiting an IP, or provisioning.')" />
            <x-signal.ui.stat :label="__('Failed servers')" :value="$metrics['failed']" :description="__('Matching provisioning failures.')" />
            <x-signal.ui.stat :label="__('Hosted websites')" :value="$metrics['websites']" :description="__('Websites attached to matching servers.')" />
            <x-signal.ui.stat :label="__('Latest matching server')" :value="$metrics['latest_at']?->diffForHumans() ?? __('Not available')" :description="$metrics['latest_at']?->toDayDateTimeString() ?? __('No matching server recorded.')" />
        </dl>
    </x-signal.ui.insights>

    <!--
     ! ------------------------------------------------------------
    ! List Servers
     ! ------------------------------------------------------------
     !-->
    @if(!$servers->isEmpty())
        <x-signal.ui.panel class="ui-inventory-list mt-6 divide-y divide-line overflow-hidden" aria-label="{{ __('Server inventory') }}">
            @foreach($servers as $server)
                <article data-server-card class="p-4 transition-colors hover:bg-surface-muted sm:p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-avatar :name="$server->label" class="ui-avatar-md shrink-0" />
                            <div class="min-w-0">
                                <a href="{{ route('servers.show', $server) }}" class="ui-link">{{ $server->label }}</a>
                                <p class="text-sm text-muted">
                                    @if (filled($server->display_name))
                                        {{ $server->name }} &middot;
                                    @endif
                                    #{{ $server->identifier }}
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($server->provisioning_status === \App\Modules\Deployer\Models\Server::STATUS_ACTIVE)
                                <x-signal.ui.badge tone="success">{{ str($server->provisioning_status)->replace('_', ' ') }}</x-signal.ui.badge>
                            @elseif ($server->provisioning_status === \App\Modules\Deployer\Models\Server::STATUS_FAILED)
                                <x-signal.ui.badge tone="danger">{{ str($server->provisioning_status)->replace('_', ' ') }}</x-signal.ui.badge>
                            @else
                                <x-signal.ui.badge tone="accent">{{ str($server->provisioning_status)->replace('_', ' ') }}</x-signal.ui.badge>
                            @endif
                            <x-signal.ui.button :href="route('servers.show', $server)" variant="secondary">{{ __('View server') }}</x-signal.ui.button>
                        </div>
                    </div>

                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Specifics') }}</dt>
                            <dd class="mt-1 text-ink">
                                {{ $server->region }}
                                <span class="mt-1 block text-muted">{{ $server->image }}</span>
                                <span class="mt-1 block text-muted">{{ str($server->type->value)->replace('-', ' ')->title() }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Public IP') }}</dt>
                            <dd class="mt-1 font-mono text-xs text-ink">{{ $server->public_ip ?? __('Not generated yet') }}</dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Private IP') }}</dt>
                            <dd class="mt-1 font-mono text-xs text-ink">{{ $server->private_ip ?? __('Not generated yet') }}</dd>
                        </div>
                        <div>
                            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Status') }}</dt>
                            <dd class="mt-1 text-ink">{{ str($server->provisioning_status)->replace('_', ' ')->title() }}</dd>
                        </div>
                    </dl>
                </article>
            @endforeach
        </x-signal.ui.panel>
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
                        <x-signal.ui.button :href="route('servers.index')" variant="primary">{{ __('Clear filters') }}</x-signal.ui.button>
                    @else
                        <x-signal.ui.button
                            :href="$serverCreateUrl"
                            data-modal-trigger="server-create-dialog"
                            aria-controls="server-create-dialog"
                            aria-expanded="{{ $serverCreateOpen ? 'true' : 'false' }}"
                            variant="secondary"
                        >{{ __('Add Server') }}</x-signal.ui.button>
                    @endif
                </x-slot:button>
            </x-lists.empty>
        </div>
    @endif

    <x-scenes.servers.create-dialog
        :types="$types"
        :providers="$providers"
        :sizes="$sizes"
        :images="$images"
        :regions="$regions"
        :recipes="$recipes"
        :plan-usage="$planUsage"
        :index-query="$serverIndexQuery"
        :open="$serverCreateOpen"
    />
</x-layouts.app>
