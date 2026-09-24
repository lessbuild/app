<x-layouts.app>

    @php
        $websiteEditOpen = $editDialogOpen;
        $websiteEditUrl = route('websites.show', ['website' => $website, 'dialog' => 'edit-website']);
        $websitePageUrl = request()->fullUrlWithoutQuery('dialog');
        $websiteEditContentUrl = route('websites.edit', ['website' => $website, 'dialog' => 'edit-website', 'fragment' => 1, 'return_to' => $websitePageUrl]);
        $repositoryCreateUrl = (string) \Illuminate\Support\Uri::of($websitePageUrl)->withQuery(['dialog' => 'create-repository']);
        $repositoryCreateContentUrl = route('dialogs.create', ['resource' => 'repository', 'return_to' => $websitePageUrl, 'website_id' => $website->id]);
        $repositoryCreateOpen = request()->query('dialog') === 'create-repository';
        $logRetentionDialogId = 'website-log-retention-dialog';
        $logRetentionDialogOpen = request()->query('dialog') === 'website-log-retention';
        $logRetentionDialogUrl = (string) \Illuminate\Support\Uri::of($websitePageUrl)->withQuery(['dialog' => 'website-log-retention']);
        $healthChecksDialogOpen = request()->query('dialog') === 'website-health-checks';
        $healthChecksDialogUrl = (string) \Illuminate\Support\Uri::of($websitePageUrl)->withQuery(['dialog' => 'website-health-checks']);
        $healthChecksContentUrl = route('websites.health-checks.index', [
            'website' => $website,
            'fragment' => 'website-health-checks',
        ]);
        $deploymentHistoryDialogOpen = request()->query('dialog') === 'website-deployment-history';
        $deploymentHistoryDialogUrl = (string) \Illuminate\Support\Uri::of($websitePageUrl)->withQuery(['dialog' => 'website-deployment-history']);
        $deploymentHistoryContentUrl = route('builds.index', [
            'website_id' => $website->id,
            'fragment' => 'deployment-history',
        ]);
        $canUpdateWebsite = auth()->user()?->can('update', $website) ?? false;
    @endphp

    <!--
     ! ------------------------------------------------------------
     ! Show passwords
     ! ------------------------------------------------------------
     !-->
    @if(session()->has("website:{$website->id}:mysql_password"))
        <x-signal.ui.panel as="aside" class="ui-panel my-4 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-primary)" role="alert">
                {{ __('The root MYSQL password is:') }}
                <b class="font-bold">{{ session()->get("website:{$website->id}:mysql_password") }}</b>
                <br>
                {{ __('This will only be shown once, so please save these passwords somewhere safe.') }}
        </x-signal.ui.panel>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Breadcrumbs
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Websites')"
        :route="route('websites.index')"
    ></x-layouts.partials.breadcrumbs>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-signal.ui.page-header
        eyebrow="{{ __('Delivery target') }}"
        icon="external-link"
        :title="$website->name"
        :description="$website->description"
    >
        <x-slot:actions>
            <x-signal.ui.button
                :href="route('builds.index', ['website_id' => $website->id])"
                data-modal-trigger="website-deployment-history-dialog"
                data-modal-content-url="{{ $deploymentHistoryContentUrl }}"
                data-modal-history-url="{{ $deploymentHistoryDialogUrl }}"
                aria-controls="website-deployment-history-dialog"
                aria-expanded="{{ $deploymentHistoryDialogOpen ? 'true' : 'false' }}"
                variant="secondary"
            >
                {{ __('Deployment history') }}
            </x-signal.ui.button>

            @if ($website->health_check_enabled
                && $website->provisioning_status === \App\Modules\Deployer\Models\Website::STATUS_ACTIVE
                && $website->server?->provisioning_status === \App\Modules\Deployer\Models\Server::STATUS_ACTIVE)
                <form method="POST" action="{{ route('websites.health.check', $website) }}">
                    @csrf
                    <x-signal.ui.button type="submit" variant="primary">
                        {{ __('Check health now') }}
                    </x-signal.ui.button>
                </form>
            @endif

            <x-signal.ui.button
                :href="$websiteEditUrl"
                data-modal-trigger="website-edit-dialog"
                data-modal-content-url="{{ $websiteEditContentUrl }}"
                aria-controls="website-edit-dialog"
                aria-expanded="{{ $websiteEditOpen ? 'true' : 'false' }}"
                variant="primary"
            >
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit Website') }}
            </x-signal.ui.button>

            <x-dialogs.delete
                id="delete-website"
                :route="route('websites.destroy', $website)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this website?')"
            ></x-dialogs.delete>

            <x-signal.ui.button type="button" variant="danger" data-modal-trigger="delete-website" aria-controls="delete-website" aria-expanded="false">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete Website') }}
            </x-signal.ui.button>

        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.local-nav class="mt-6" :label="__('Website sections')">
        <a href="#website-information" class="ui-local-nav__link">{{ __('Overview') }}</a>
        <a href="#website-operations" class="ui-local-nav__link">{{ __('Operations') }}</a>
        <a href="#website-health" class="ui-local-nav__link">{{ __('Health') }}</a>
        <a href="#website-runtime-logs" class="ui-local-nav__link">{{ __('Logs') }}</a>
        <a href="#website-repositories" class="ui-local-nav__link">{{ __('Repositories') }}</a>
    </x-signal.ui.local-nav>

    @if ($website->provisioning_status === \App\Modules\Deployer\Models\Website::STATUS_FAILED)
        <x-signal.ui.panel as="aside" class="ui-panel my-4 border-l-4 border-line bg-surface-muted p-4 text-ink" style="border-left-color: var(--ui-danger)" role="alert">
            <p class="font-semibold">{{ __('Website provisioning failed') }}</p>
            <p class="mt-1 text-sm text-muted">{{ $website->provisioning_error }}</p>
            @error('retry')
                <p class="mt-2 text-sm font-semibold">{{ $message }}</p>
            @enderror
            <form method="POST" action="{{ route('websites.provisioning.retry', $website) }}" class="mt-3">
                @csrf
                <x-signal.ui.button type="submit" variant="primary">{{ __('Retry provisioning') }}</x-signal.ui.button>
            </form>
        </x-signal.ui.panel>
    @endif

    @if ($website->previous_server_id)
        <x-signal.ui.panel as="aside" class="ui-panel my-4 border-l-4 border-line bg-surface-muted p-4 text-ink" style="border-left-color: var(--ui-warning)" role="status">
            <p class="font-semibold">{{ __('Previous server cleanup pending') }}</p>
            <p class="mt-1 text-sm text-muted">
                @if ($website->placement_cleanup_error)
                    {{ $website->placement_cleanup_error }}
                @elseif ($website->provisioning_status === \App\Modules\Deployer\Models\Website::STATUS_ACTIVE)
                    {{ __('The website is active on its target server and cleanup of its old placement is queued.') }}
                @elseif ($website->provisioning_status === \App\Modules\Deployer\Models\Website::STATUS_FAILED)
                    {{ __('Target provisioning failed. The source placement was retained so you can retry safely.') }}
                @else
                    {{ __('The source placement remains available until target provisioning succeeds.') }}
                @endif
            </p>
            @if ($website->placement_cleanup_error)
                <form method="POST" action="{{ route('websites.placement.cleanup', $website) }}" class="mt-3">
                    @csrf
                    <x-signal.ui.button type="submit" variant="primary">{{ __('Retry cleanup') }}</x-signal.ui.button>
                </form>
            @endif
        </x-signal.ui.panel>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Website information
     ! ------------------------------------------------------------
     !-->
    <x-signal.ui.panel as="section" id="website-information" class="ui-panel mt-6 scroll-mt-24 p-5" data-website-overview aria-labelledby="website-information-heading">
        <h2 id="website-information-heading" class="sr-only">{{ __('Website information') }}</h2>
        <dl class="grid gap-5 text-sm sm:grid-cols-2 xl:grid-cols-4">
        <div class="flex items-start gap-3">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-muted" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#external-link"></use>
            </svg>
            <div>
                <dt class="ui-eyebrow text-[0.65rem]">{{ __('URL') }}</dt>
                <dd class="mt-1 break-all font-mono text-xs text-ink">{{ $website->url }}</dd>
            </div>
        </div>
        <div>
            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Deployment health check') }}</dt>
            <dd class="mt-1 font-mono text-xs text-ink">{{ $website->health_check_enabled ? $website->health_check_path : __('Disabled') }}</dd>
        </div>
        @if ($website->health_check_enabled)
            <div>
                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Current health') }}</dt>
                <dd class="mt-1 flex flex-wrap items-center gap-2">
                    @if ($website->health_status === \App\Modules\Deployer\Models\Website::HEALTH_HEALTHY)
                        <x-signal.ui.badge tone="success">{{ str($website->health_status)->title() }}</x-signal.ui.badge>
                    @elseif ($website->health_status === \App\Modules\Deployer\Models\Website::HEALTH_UNHEALTHY)
                        <x-signal.ui.badge tone="danger">{{ str($website->health_status)->title() }}</x-signal.ui.badge>
                    @else
                        <x-signal.ui.badge>{{ str($website->health_status)->title() }}</x-signal.ui.badge>
                    @endif
                @if ($website->health_last_checked_at)
                    <span class="text-xs text-muted">{{ $website->health_last_checked_at->diffForHumans() }}</span>
                @endif
                </dd>
            </div>
            <div>
                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Automatic monitoring') }}</dt>
                <dd class="mt-1 flex flex-wrap items-center gap-2">
                    @if ($website->health_monitoring_enabled)
                        <x-signal.ui.badge tone="success">{{ __('Enabled') }}</x-signal.ui.badge>
                    @else
                        <x-signal.ui.badge tone="warning">{{ __('Paused') }}</x-signal.ui.badge>
                    @endif
                    <span class="text-xs text-muted">{{ trans_choice('every :count minute|every :count minutes', $website->health_check_interval_minutes, ['count' => $website->health_check_interval_minutes]) }}</span>
                </dd>
            </div>
            <div>
                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Outage confirmation') }}</dt>
                <dd class="mt-1 text-xs text-ink">{{ trans_choice('After :count consecutive failure|After :count consecutive failures', $website->health_failure_threshold, ['count' => $website->health_failure_threshold]) }}</dd>
            </div>
        @endif
        <div>
            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Retained releases') }}</dt>
            <dd class="mt-1 text-xs text-ink">{{ $website->release_retention }}</dd>
        </div>
        </dl>
    </x-signal.ui.panel>

    @if ($website->health_status === \App\Modules\Deployer\Models\Website::HEALTH_UNHEALTHY && $website->health_last_error)
        <x-signal.ui.panel as="aside" class="ui-panel mt-4 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-danger)" role="alert">
            <strong>{{ __('Health check failed:') }}</strong> {{ $website->health_last_error }}
        </x-signal.ui.panel>
    @endif

    @php
        $websiteOperationsNeedAttention = $website->provisioning_status !== \App\Modules\Deployer\Models\Website::STATUS_ACTIVE
            || $website->previous_server_id !== null;
    @endphp
    <x-signal.ui.panel as="details" id="website-operations" class="group ui-panel mt-6 overflow-hidden" :open="$websiteOperationsNeedAttention">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-ink [&::-webkit-details-marker]:hidden">
            <span>
                <span class="ui-eyebrow block">{{ __('Operations') }}</span>
                <span class="mt-1 block text-lg font-extrabold">{{ __('Provisioning timeline') }}</span>
                <span class="mt-1 block text-sm font-normal text-muted">
                    {{ str($website->provisioning_status ?? 'unknown')->replace('_', ' ')->headline() }}
                    @if ($website->previous_server_id)
                        · {{ __('Previous placement cleanup pending') }}
                    @endif
                </span>
            </span>
            <span class="text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <div class="space-y-6 border-t border-line p-5">
            <livewire:website-setup :model="$website" />
            <livewire:website-provisioning-log :website="$website" />
        </div>
    </x-signal.ui.panel>

    <x-signal.ui.panel as="section" id="website-health" class="ui-panel mt-8 scroll-mt-24 p-5 sm:p-6" data-website-health aria-labelledby="health-history-heading">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="ui-eyebrow">{{ __('Health evidence') }}</p>
                <h2 id="health-history-heading" class="mt-2 text-xl font-extrabold tracking-tight text-ink">{{ __('Recent health checks') }}</h2>
                <p class="mt-1 text-sm text-muted">
                    {{ __('Accepted manual and automatic results are retained for the latest 100 checks. This page shows the newest 20.') }}
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <x-signal.ui.button
                    :href="route('websites.health-checks.index', $website)"
                    data-modal-trigger="website-health-checks-dialog"
                    data-modal-content-url="{{ $healthChecksContentUrl }}"
                    data-modal-history-url="{{ $healthChecksDialogUrl }}"
                    aria-controls="website-health-checks-dialog"
                    aria-expanded="{{ $healthChecksDialogOpen ? 'true' : 'false' }}"
                    variant="secondary"
                >{{ __('View all health checks') }}</x-signal.ui.button>
                @if ($healthChecks->isNotEmpty())
                    <x-signal.ui.button :href="route('websites.health-checks.export', $website)" variant="secondary">{{ __('Export health history') }}</x-signal.ui.button>
                @endif
            </div>
        </div>

        <x-signal.ui.insights
            id="website-health-insights"
            class="mt-4"
            :summary="trans_choice(':count retained check|:count retained checks', $healthMetrics['total'], ['count' => $healthMetrics['total']])"
        >
            <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-signal.ui.stat :label="__('Retained checks')" :value="$healthMetrics['total']" :description="__('Newest :limit maximum', ['limit' => \App\Modules\Deployer\Models\WebsiteHealthCheck::MAX_PER_WEBSITE])" />
                <x-signal.ui.stat :label="__('Observed check success')" :value="$healthMetrics['success_rate'] !== null ? $healthMetrics['success_rate'].'%' : __('Not available')" :description="trans_choice(':count successful check|:count successful checks', $healthMetrics['successful'], ['count' => $healthMetrics['successful']])" />
                <x-signal.ui.stat :label="__('Median healthy response')" :value="$healthMetrics['median_healthy_duration_ms'] !== null ? $healthMetrics['median_healthy_duration_ms'].' ms' : __('Not recorded')" :description="__('Failed and unreported timings are excluded.')" />
                <x-signal.ui.stat :label="__('Current failure streak')" :value="$healthMetrics['failure_streak']" :description="trans_choice(':count consecutive failed check|:count consecutive failed checks', $healthMetrics['failure_streak'], ['count' => $healthMetrics['failure_streak']])" />
            </dl>
        </x-signal.ui.insights>
        <p class="mt-3 text-xs text-muted">
            {{ __('These figures summarize retained observations and are not an SLA uptime calculation.') }}
        </p>

        @if ($healthChecks->isEmpty())
            <x-signal.ui.empty-state class="mt-4" :title="__('No health checks have been recorded yet.')" />
        @else
            <x-signal.ui.panel as="details" id="website-health-history" class="group ui-panel mt-4 overflow-hidden">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 font-bold text-ink [&::-webkit-details-marker]:hidden">
                    <span>{{ __('Latest check results') }}</span>
                    <span class="text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
                </summary>
                <div class="divide-y divide-line border-t border-line">
                    @foreach ($healthChecks as $check)
                        @include('scenes.websites._health-check-card', ['check' => $check])
                    @endforeach
                </div>
            </x-signal.ui.panel>
        @endif
    </x-signal.ui.panel>

    @php
        $runtimeLogsNeedAttention = $runtimeLogs->contains(fn ($snapshot) => in_array($snapshot?->status, [
            \App\Modules\Deployer\Models\WebsiteLogSnapshot::STATUS_QUEUED,
            \App\Modules\Deployer\Models\WebsiteLogSnapshot::STATUS_REFRESHING,
            \App\Modules\Deployer\Models\WebsiteLogSnapshot::STATUS_FAILED,
        ], true));
    @endphp
    <x-signal.ui.panel as="details" id="website-runtime-logs" class="group ui-panel mt-6 overflow-hidden" :open="$runtimeLogsNeedAttention">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-ink [&::-webkit-details-marker]:hidden">
            <span>
                <span class="ui-eyebrow block">{{ __('Runtime') }}</span>
                <span class="mt-1 block text-lg">{{ __('Live log snapshots') }}</span>
                <span class="mt-1 block text-sm font-normal text-muted">{{ __('Application and access output with bounded retention.') }}</span>
            </span>
            <span class="text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <section class="border-t border-line p-5" id="runtime-logs" x-data="{ logType: 'application' }">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="ui-eyebrow">{{ __('Runtime') }}</p>
                <h2 class="mt-2 text-xl font-extrabold text-ink">{{ __('Live log snapshots') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Fetch the latest encrypted application or per-site access output without exposing another website’s traffic.') }}</p>
            </div>
            <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Log type') }}">
                @foreach (\App\Modules\Deployer\Models\WebsiteLogSnapshot::TYPES as $type)
                    <x-signal.ui.button
                        type="button"
                        variant="secondary"
                        class="ui-btn-sm"
                        role="tab"
                        x-bind:aria-selected="logType === '{{ $type }}' ? 'true' : 'false'"
                        @click="logType='{{ $type }}'"
                    >{{ ucfirst($type) }}</x-signal.ui.button>
                @endforeach
            </div>
        </div>
        @foreach (\App\Modules\Deployer\Models\WebsiteLogSnapshot::TYPES as $type)
            @php
                $snapshot = $runtimeLogs->get($type);
            @endphp
            <div x-show="logType === '{{ $type }}'" class="mt-4" data-runtime-log-console data-refresh-url="{{ route('websites.runtime-logs.refresh', [$website, $type]) }}" data-report-url="{{ route('websites.runtime-logs.show', [$website, $type]) }}">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-muted" data-log-status>{{ $snapshot?->refreshed_at ? __('Updated :time', ['time' => $snapshot->refreshed_at->diffForHumans()]) : __('Not collected yet') }} · {{ ucfirst($snapshot?->status ?? 'idle') }}</p>
                    <form method="POST" action="{{ route('websites.runtime-logs.refresh', [$website, $type]) }}">
                        @csrf
                        <x-signal.ui.button type="submit" variant="primary" class="ui-btn-sm">{{ __('Refresh :type log', ['type' => $type]) }}</x-signal.ui.button>
                    </form>
                </div>
                <div class="mb-3 grid gap-3 sm:grid-cols-[1fr_12rem_auto]">
                    <label class="sr-only" for="website-{{ $type }}-log-search">{{ __('Search log lines') }}</label>
                    <x-signal.ui.input id="website-{{ $type }}-log-search" type="search" data-log-search class="ui-input" placeholder="{{ __('Search log lines') }}" :restore="false" />
                    <label class="sr-only" for="website-{{ $type }}-log-level">{{ __('Log level') }}</label>
                    <x-signal.ui.select id="website-{{ $type }}-log-level" data-log-level class="ui-input">
                        <option value="">{{ __('All levels') }}</option>
                        <option value="emergency">Emergency</option>
                        <option value="error">Error</option>
                        <option value="warning">Warning</option>
                        <option value="info">Info</option>
                        <option value="debug">Debug</option>
                    </x-signal.ui.select>
                    <label class="ui-choice min-h-11 items-center gap-2 px-3 text-sm text-ink">
                        <x-signal.ui.input type="checkbox" data-log-live class="ui-check" :restore="false" />
                        {{ __('Live') }}
                    </label>
                </div>
                @if($snapshot?->error)
                    <x-signal.ui.alert tone="danger" class="mb-3 border-l-4">{{ $snapshot->error }}</x-signal.ui.alert>
                @endif
                <pre data-log-output class="ui-console ui-console-output max-h-[32rem] whitespace-pre-wrap break-words p-5 font-mono text-xs leading-5">{{ $snapshot?->log ?: __('No log output captured.') }}</pre>
            </div>
        @endforeach
        @if ($canUpdateWebsite)
            <div class="mt-4 border-t border-line pt-4">
                <x-signal.ui.button
                    :href="$logRetentionDialogUrl"
                    data-modal-trigger="{{ $logRetentionDialogId }}"
                    aria-controls="{{ $logRetentionDialogId }}"
                    aria-expanded="{{ $logRetentionDialogOpen ? 'true' : 'false' }}"
                    variant="secondary"
                >{{ __('Configure retention') }}</x-signal.ui.button>
            </div>
        @endif
        </section>
    </x-signal.ui.panel>
    @if ($canUpdateWebsite)
        <x-scenes.websites.log-retention-dialog
            :website="$website"
            :open="$logRetentionDialogOpen"
        />
    @endif

    <x-dialogs.modal
        id="website-health-checks-dialog"
        :title="__('Health check history')"
        :description="__('Review retained health evidence without leaving this website.')"
        :open="$healthChecksDialogOpen"
        body-class="p-0"
    >
        <div data-modal-content>
            <p class="p-5 text-sm text-muted">{{ __('Loading health check history…') }}</p>
        </div>
    </x-dialogs.modal>

    <x-dialogs.modal
        id="website-deployment-history-dialog"
        :title="__('Deployment history')"
        :description="__('Review recent deployments without leaving this website.')"
        :open="$deploymentHistoryDialogOpen"
        body-class="p-0"
    >
        <div data-modal-content>
            <p class="p-5 text-sm text-muted">{{ __('Loading deployment history…') }}</p>
        </div>
    </x-dialogs.modal>

    <!--
     ! ------------------------------------------------------------
     ! Quick Actions
     ! ------------------------------------------------------------
    !-->
    <section id="website-repositories" class="mt-8 scroll-mt-24" data-website-repositories aria-labelledby="attached-repositories-heading">
        <x-signal.ui.card class="ui-panel p-5 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="ui-eyebrow">{{ __('Deployments') }}</p>
                    <h2 id="attached-repositories-heading" class="mt-2 text-lg font-extrabold text-ink">{{ __('Attached Repositories') }}</h2>
                </div>
                <x-signal.ui.button
                    :href="$repositoryCreateUrl"
                    data-modal-trigger="repository-create-dialog"
                    data-modal-content-url="{{ $repositoryCreateContentUrl }}"
                    aria-controls="repository-create-dialog"
                    aria-expanded="{{ $repositoryCreateOpen ? 'true' : 'false' }}"
                    variant="ghost"
                    class="ui-btn-sm shrink-0"
                >{{ __('Add repository') }}</x-signal.ui.button>
            </div>
            <ul role="list" class="mt-4 divide-y divide-line">
                @forelse($repositories as $repository)
                    <li>
                        <a href="{{ route('repositories.show', $repository) }}" class="ui-link flex min-w-0 items-center gap-4 py-3 hover:bg-surface-muted">
                            <x-avatar :name="$repository->name" class="ui-avatar-sm shrink-0 text-xs" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-ink">{{ $repository->name }}</span>
                                <span class="block truncate text-sm text-muted">{{ $repository->url }}</span>
                            </span>
                            @if ($repository->latestBuild)
                                <x-signal.ui.badge>{{ str($repository->latestBuild->status)->replace('_', ' ') }}</x-signal.ui.badge>
                            @else
                                <x-signal.ui.badge>{{ __('Not deployed') }}</x-signal.ui.badge>
                            @endif
                        </a>
                    </li>
                @empty
                    <li class="pt-3">
                        <x-signal.ui.panel class="ui-panel border-l-4 border-line bg-surface-muted p-4 text-sm text-muted" style="border-left-color: var(--ui-primary)" role="status">{{ __('No repositories attached to website') }}</x-signal.ui.panel>
                    </li>
                @endforelse
            </ul>
            @if ($repositories->hasPages())
                <div class="mt-4 border-t border-line pt-4">{{ $repositories->links() }}</div>
            @endif
        </x-signal.ui.card>
    </section>

    <x-scenes.websites.edit-dialog
        :website="$website"
        :servers="$servers"
        :open="$websiteEditOpen"
        :cancel-url="$websitePageUrl"
        :content-url="$websiteEditContentUrl"
    />
</x-layouts.app>
