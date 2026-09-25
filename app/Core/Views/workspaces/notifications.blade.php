@php
    $filterQuery = array_filter($filters, fn (string $value): bool => $value !== 'all');
    $severityOptions = \App\Core\Data\Notifications\WorkspaceNotificationSeverity::cases();
    $hasVisibleUnread = $items->contains(fn ($item): bool => ! $item->read);
@endphp

<x-signal.layouts.platform
    :title="__('Notifications')"
    :description="__('A project-aware inbox for recent product activity and connected workflow updates.')"
    :navigation="['unread_notifications' => $unreadCount]"
    :account-user="$user"
    :current-workspace="$workspace"
    :workspaces="$workspaces"
    :context-projects="$contextProjects"
>
    <x-signal.ui.page-header
        :eyebrow="$workspace->name"
        :title="__('Notifications')"
        :description="__('Follow deployment, incident, recovery, and product updates grouped by project and environment.')"
    >
        <x-slot:actions>
            <x-signal.ui.button :href="route('core.workspace.notifications.export', ['workspace' => $workspace, ...$filterQuery])" variant="secondary">{{ __('Export CSV') }}</x-signal.ui.button>
            @if ($hasVisibleUnread)
                <form method="POST" action="{{ route('core.workspace.notifications.read-all', ['workspace' => $workspace, ...$filterQuery]) }}">
                    @csrf
                    <x-signal.ui.button type="submit" variant="secondary">{{ __('Mark visible as read') }}</x-signal.ui.button>
                </form>
            @endif
            <x-signal.ui.button :href="route('core.workspace.dashboard', $workspace)" variant="primary">{{ __('Workspace overview') }}</x-signal.ui.button>
        </x-slot:actions>
    </x-signal.ui.page-header>

    <section class="mt-7" aria-labelledby="notification-filter-heading">
        <x-signal.ui.card as="form" method="GET" :action="route('core.workspace.notifications', $workspace)" class="grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-4">
            <h2 id="notification-filter-heading" class="sr-only">{{ __('Filter notifications') }}</h2>
            <x-signal.ui.select-field name="state" id="notification-state" :label="__('Read state')">
                <option value="all" @selected($filters['state'] === 'all')>{{ __('All') }}</option>
                <option value="unread" @selected($filters['state'] === 'unread')>{{ __('Unread') }}</option>
                <option value="read" @selected($filters['state'] === 'read')>{{ __('Read') }}</option>
            </x-signal.ui.select-field>
            <x-signal.ui.select-field name="product" id="notification-product" :label="__('Product')">
                <option value="all" @selected($filters['product'] === 'all')>{{ __('All products') }}</option>
                @foreach ($notificationProducts as $product)
                    <option value="{{ $product }}" @selected($filters['product'] === $product)>{{ config('platform.products.'.$product.'.label', str($product)->headline()) }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.select-field name="severity" id="notification-severity" :label="__('Severity')">
                <option value="all" @selected($filters['severity'] === 'all')>{{ __('All severities') }}</option>
                @foreach ($severityOptions as $severity)
                    <option value="{{ $severity->value }}" @selected($filters['severity'] === $severity->value)>{{ $severity->label() }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.select-field name="project" id="notification-project" :label="__('Project')">
                <option value="all" @selected($filters['project'] === 'all')>{{ __('All projects') }}</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->getKey() }}" @selected($filters['project'] === (string) $project->getKey())>{{ $project->name }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <div class="flex flex-wrap items-center justify-end gap-2 sm:col-span-2 xl:col-span-4">
                <x-signal.ui.button :href="route('core.workspace.notifications', $workspace)" variant="ghost">{{ __('Clear filters') }}</x-signal.ui.button>
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Apply filters') }}</x-signal.ui.button>
            </div>
        </x-signal.ui.card>
    </section>

    <section class="mt-5" aria-labelledby="notification-saved-filters-heading">
        <x-signal.ui.card class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <div>
                <p class="ui-eyebrow">{{ __('Personal shortcuts') }}</p>
                <h2 id="notification-saved-filters-heading" class="mt-1 text-base font-extrabold text-ink">{{ __('Saved filters') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Save this view for your account in this workspace. Saved filters do not change product alert delivery.') }}</p>
            </div>
            <form method="POST" action="{{ route('core.workspace.notifications.saved-filters.store', ['workspace' => $workspace, ...$filterQuery]) }}" class="grid gap-3 sm:grid-cols-[minmax(12rem,1fr)_auto] sm:items-end">
                @csrf
                <input type="hidden" name="state" value="{{ $filters['state'] }}">
                <input type="hidden" name="product" value="{{ $filters['product'] }}">
                <input type="hidden" name="severity" value="{{ $filters['severity'] }}">
                <input type="hidden" name="project" value="{{ $filters['project'] }}">
                <x-signal.ui.input-field name="name" id="notification-saved-filter-name" :label="__('Filter name')" :value="old('name')" maxlength="80" required />
                <x-signal.ui.button type="submit" variant="secondary">{{ __('Save current view') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.card>
        @error('name')
            <x-signal.ui.alert tone="danger" class="mt-3" role="alert">{{ $message }}</x-signal.ui.alert>
        @enderror

        @if ($savedFilters->isNotEmpty())
            <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($savedFilters as $savedFilter)
                    @php
                        $savedFilterQuery = array_filter(array_merge(['state' => 'all', 'product' => 'all', 'severity' => 'all', 'project' => 'all'], $savedFilter->filters ?? []), fn (string $value): bool => $value !== 'all');
                    @endphp
                    <x-signal.ui.card class="flex flex-wrap items-center justify-between gap-3 p-3">
                        <x-signal.ui.link :href="route('core.workspace.notifications', ['workspace' => $workspace, ...$savedFilterQuery])" class="font-semibold">{{ $savedFilter->name }}</x-signal.ui.link>
                        <form method="POST" action="{{ route('core.workspace.notifications.saved-filters.destroy', ['workspace' => $workspace, 'savedFilter' => $savedFilter, ...$filterQuery]) }}">
                            @csrf
                            @method('DELETE')
                            <x-signal.ui.button type="submit" variant="ghost" class="ui-btn-sm">{{ __('Remove') }}</x-signal.ui.button>
                        </form>
                    </x-signal.ui.card>
                @endforeach
            </div>
        @endif
    </section>

    @if ($unavailableProducts->isNotEmpty())
        <x-signal.ui.alert tone="warning" class="mt-5" role="status">
            {{ __('Some recent activity is temporarily unavailable from :products. Other connected products are still shown.', ['products' => $unavailableProducts->join(', ')]) }}
        </x-signal.ui.alert>
    @endif

    <section class="mt-8" aria-labelledby="notification-thread-heading">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Project and environment threads') }}</p>
                <h2 id="notification-thread-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Recent updates') }}</h2>
            </div>
            <p class="text-sm text-muted">{{ trans_choice(':count unread item|:count unread items', $visibleUnreadCount, ['count' => $visibleUnreadCount]) }}</p>
        </div>

        @if ($items->isEmpty())
            <x-signal.ui.empty-state
                :title="$unavailableProducts->isNotEmpty() ? __('Some notification sources are unavailable') : __('No notifications match these filters')"
                :description="$unavailableProducts->isNotEmpty() ? __('Try again after the product databases respond. Available product activity will appear here automatically.') : __('New deployment, incident, recovery, and product updates will appear here when they are recorded for projects you can access.')"
                :icon="$unavailableProducts->isNotEmpty() ? 'clock' : 'bell'"
            >
                <x-slot:action>
                    <x-signal.ui.button :href="route('core.workspace.dashboard', $workspace)" variant="primary">{{ __('Open workspace projects') }}</x-signal.ui.button>
                </x-slot:action>
            </x-signal.ui.empty-state>
        @else
            <div class="grid gap-4">
                @foreach ($threads as $thread)
                    @php($threadFirst = $thread->first())
                    <x-signal.ui.card as="section" class="p-4 sm:p-5" aria-label="{{ $threadFirst->projectName ? __('Updates for :project', ['project' => $threadFirst->projectName]) : ($threadFirst->security ? __('Account and security notifications') : __('Workspace notifications')) }}">
                        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-line pb-3">
                            <div class="min-w-0">
                                <p class="ui-eyebrow">{{ $threadFirst->environmentName ?: ($threadFirst->security ? __('Account security') : __('Workspace thread')) }}</p>
                                <h3 class="mt-1 truncate text-base font-extrabold text-ink">{{ $threadFirst->projectName ?: ($threadFirst->security ? __('Account notification') : str($threadFirst->sourceCategory ?? 'workspace update')->replace('_', ' ')->headline()) }}</h3>
                            </div>
                            @if ($threadFirst->projectUrl)
                                <x-signal.ui.link :href="$threadFirst->projectUrl" size="sm">{{ __('Open project') }}</x-signal.ui.link>
                            @endif
                        </header>

                        <ol class="divide-y divide-line" aria-label="{{ __('Updates in this project thread') }}">
                            @foreach ($thread as $item)
                                <li class="grid gap-3 py-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-signal.ui.badge :tone="$item->severity->tone()">{{ $item->severity->label() }}</x-signal.ui.badge>
                                            <x-signal.ui.badge tone="neutral">{{ $item->productLabel }}</x-signal.ui.badge>
                                            <x-signal.ui.badge :tone="$item->read ? 'neutral' : 'accent'">{{ $item->read ? __('Read') : __('Unread') }}</x-signal.ui.badge>
                                        </div>
                                        <h4 class="mt-2 font-bold text-ink">{{ $item->title }}</h4>
                                        <p class="mt-1 text-sm leading-6 text-muted">{{ $item->detail }}</p>
                                        <time class="mt-2 block text-xs text-subtle" datetime="{{ $item->occurredAt->toIso8601String() }}">{{ $item->occurredAt->diffForHumans() }}</time>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                                        @if ($item->resultUrl)
                                            <x-signal.ui.button :href="$item->resultUrl" variant="secondary" class="ui-btn-sm">{{ __('Open :product', ['product' => $item->productLabel]) }}</x-signal.ui.button>
                                        @endif
                                        @if ($item->read)
                                            <form method="POST" action="{{ route('core.workspace.notifications.unread', ['workspace' => $workspace, 'notificationKey' => $item->key, ...$filterQuery]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <x-signal.ui.button type="submit" variant="ghost" class="ui-btn-sm">{{ __('Mark unread') }}</x-signal.ui.button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('core.workspace.notifications.read', ['workspace' => $workspace, 'notificationKey' => $item->key, ...$filterQuery]) }}">
                                                @csrf
                                                <x-signal.ui.button type="submit" variant="ghost" class="ui-btn-sm">{{ __('Mark read') }}</x-signal.ui.button>
                                            </form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </x-signal.ui.card>
                @endforeach
            </div>
        @endif

        <p class="mt-3 text-xs leading-5 text-subtle">{{ __('Showing up to 100 recent updates and recipient-owned account notices. Product records and delivery channels remain authoritative.') }}</p>
    </section>

    <section class="mt-10" aria-labelledby="inbox-preferences-heading">
        <div class="mb-4 max-w-3xl">
            <p class="ui-eyebrow">{{ __('Personal inbox settings') }}</p>
            <h2 id="inbox-preferences-heading" class="mt-1 text-lg font-extrabold text-ink">{{ __('Choose what appears here') }}</h2>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('These preferences only hide or show items in your Core inbox. They do not change email, alert destinations, escalation policies, or product notification delivery.') }}</p>
        </div>

        <x-signal.ui.card as="form" method="POST" :action="route('core.workspace.notifications.preferences.update', $workspace)" class="grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-5">
            @csrf
            @method('PUT')
            <x-signal.ui.select-field name="project_id" id="preference-project" :label="__('Scope')">
                <option value="">{{ __('Workspace default') }}</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->getKey() }}" @selected(old('project_id') === (string) $project->getKey())>{{ $project->name }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.select-field name="product" id="preference-product" :label="__('Product')" required>
                @foreach ($products as $product)
                    <option value="{{ $product }}" @selected(old('product') === $product)>{{ config('platform.products.'.$product.'.label', str($product)->headline()) }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.select-field name="severity" id="preference-severity" :label="__('Severity')" required>
                @foreach ($severityOptions as $severity)
                    <option value="{{ $severity->value }}" @selected(old('severity') === $severity->value)>{{ $severity->label() }}</option>
                @endforeach
            </x-signal.ui.select-field>
            <x-signal.ui.select-field name="enabled" id="preference-enabled" :label="__('Inbox display')" required>
                <option value="1" @selected(old('enabled', '1') === '1')>{{ __('Show') }}</option>
                <option value="0" @selected(old('enabled') === '0')>{{ __('Hide') }}</option>
            </x-signal.ui.select-field>
            <div class="flex items-end">
                <x-signal.ui.button type="submit" variant="secondary" class="w-full">{{ __('Save preference') }}</x-signal.ui.button>
            </div>
        </x-signal.ui.card>

        @if ($preferences->isNotEmpty())
            <div class="mt-4 grid gap-3">
                @foreach ($preferences as $preference)
                    @php($preferenceSeverity = \App\Core\Data\Notifications\WorkspaceNotificationSeverity::tryFrom($preference->severity))
                    <x-signal.ui.card class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <p class="text-sm text-ink">
                            <strong>{{ $preference->project?->name ?? __('Workspace default') }}</strong>
                            <span class="text-muted">· {{ config('platform.products.'.$preference->product.'.label', str($preference->product)->headline()) }} · {{ $preferenceSeverity?->label() ?? $preference->severity }} · {{ $preference->enabled ? __('Shown in inbox') : __('Hidden from inbox') }}</span>
                        </p>
                        <form method="POST" action="{{ route('core.workspace.notifications.preferences.destroy', ['workspace' => $workspace, 'preference' => $preference]) }}">
                            @csrf
                            @method('DELETE')
                            <x-signal.ui.button type="submit" variant="ghost" class="ui-btn-sm">{{ __('Reset') }}</x-signal.ui.button>
                        </form>
                    </x-signal.ui.card>
                @endforeach
            </div>
        @endif
    </section>
</x-signal.layouts.platform>
