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
        $canUpdateWebsite = auth()->user()?->can('update', $website) ?? false;
    @endphp

    <!--
     ! ------------------------------------------------------------
     ! Show passwords
     ! ------------------------------------------------------------
     !-->
    @if(session()->has("website:{$website->id}:mysql_password"))
        <div class="my-4">
            <x-ui.alert tone="warning">
                {{ __('The root MYSQL password is:') }}
                <b class="font-bold">{{ session()->get("website:{$website->id}:mysql_password") }}</b>
                <br>
                {{ __('This will only be shown once, so please save these passwords somewhere safe.') }}
            </x-ui.alert>
        </div>
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
    <x-layouts.partials.heading
        icon="external-link"
        :title="$website->name"
        :description="$website->description"
    >
        <x-slot:buttons>
            <x-ui.button :href="route('builds.index', ['website_id' => $website->id])" variant="secondary">
                {{ __('Deployment history') }}
            </x-ui.button>

            @if ($website->health_check_enabled
                && $website->provisioning_status === \App\Models\Website::STATUS_ACTIVE
                && $website->server?->provisioning_status === \App\Models\Server::STATUS_ACTIVE)
                <form method="POST" action="{{ route('websites.health.check', $website) }}">
                    @csrf
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Check health now') }}
                    </x-ui.button>
                </form>
            @endif

            <x-ui.button
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
            </x-ui.button>

            <x-dialogs.delete
                id="delete-website"
                :route="route('websites.destroy', $website)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this website?')"
            ></x-dialogs.delete>

            <button type="button" class="button button--danger" data-modal-trigger="delete-website" aria-controls="delete-website" aria-expanded="false">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete Website') }}
            </button>

        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if ($website->provisioning_status === \App\Models\Website::STATUS_FAILED)
        <x-ui.alert tone="danger" class="my-4">
            <p class="font-semibold">{{ __('Website provisioning failed') }}</p>
            <p class="text-sm">{{ $website->provisioning_error }}</p>
            @error('retry')
                <p class="mt-2 text-sm font-semibold">{{ $message }}</p>
            @enderror
            <form method="POST" action="{{ route('websites.provisioning.retry', $website) }}" class="mt-3">
                @csrf
                <x-ui.button type="submit" variant="primary">{{ __('Retry provisioning') }}</x-ui.button>
            </form>
        </x-ui.alert>
    @endif

    @if ($website->previous_server_id)
        <x-ui.alert tone="warning" class="my-4">
            <p class="font-semibold">{{ __('Previous server cleanup pending') }}</p>
            <p class="text-sm">
                @if ($website->placement_cleanup_error)
                    {{ $website->placement_cleanup_error }}
                @elseif ($website->provisioning_status === \App\Models\Website::STATUS_ACTIVE)
                    {{ __('The website is active on its target server and cleanup of its old placement is queued.') }}
                @elseif ($website->provisioning_status === \App\Models\Website::STATUS_FAILED)
                    {{ __('Target provisioning failed. The source placement was retained so you can retry safely.') }}
                @else
                    {{ __('The source placement remains available until target provisioning succeeds.') }}
                @endif
            </p>
            @if ($website->placement_cleanup_error)
                <form method="POST" action="{{ route('websites.placement.cleanup', $website) }}" class="mt-3">
                    @csrf
                    <x-ui.button type="submit" variant="primary">{{ __('Retry cleanup') }}</x-ui.button>
                </form>
            @endif
        </x-ui.alert>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Website information
     ! ------------------------------------------------------------
     !-->
    <x-ui.card class="mt-6 p-5">
        <dl class="grid gap-5 text-sm sm:grid-cols-2 xl:grid-cols-4">
        <div class="flex items-start gap-3">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-secondary" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#external-link"></use>
            </svg>
            <div>
                <dt class="font-semibold text-primary">{{ __('URL') }}</dt>
                <dd class="mt-1 break-all font-mono text-xs text-secondary">{{ $website->url }}</dd>
            </div>
        </div>
        <div>
            <dt class="font-semibold text-primary">{{ __('Deployment health check') }}</dt>
            <dd class="mt-1 font-mono text-xs text-secondary">{{ $website->health_check_enabled ? $website->health_check_path : __('Disabled') }}</dd>
        </div>
        @if ($website->health_check_enabled)
            <div>
                <dt class="font-semibold text-primary">{{ __('Current health') }}</dt>
                <dd class="mt-1 flex flex-wrap items-center gap-2">
                    @if ($website->health_status === \App\Models\Website::HEALTH_HEALTHY)
                        <x-ui.badge tone="success">{{ str($website->health_status)->title() }}</x-ui.badge>
                    @elseif ($website->health_status === \App\Models\Website::HEALTH_UNHEALTHY)
                        <x-ui.badge tone="danger">{{ str($website->health_status)->title() }}</x-ui.badge>
                    @else
                        <x-ui.badge>{{ str($website->health_status)->title() }}</x-ui.badge>
                    @endif
                @if ($website->health_last_checked_at)
                    <span class="text-xs text-secondary">{{ $website->health_last_checked_at->diffForHumans() }}</span>
                @endif
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-primary">{{ __('Automatic monitoring') }}</dt>
                <dd class="mt-1 flex flex-wrap items-center gap-2">
                    @if ($website->health_monitoring_enabled)
                        <x-ui.badge tone="success">{{ __('Enabled') }}</x-ui.badge>
                    @else
                        <x-ui.badge tone="warning">{{ __('Paused') }}</x-ui.badge>
                    @endif
                    <span class="text-xs text-secondary">{{ trans_choice('every :count minute|every :count minutes', $website->health_check_interval_minutes, ['count' => $website->health_check_interval_minutes]) }}</span>
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-primary">{{ __('Outage confirmation') }}</dt>
                <dd class="mt-1 text-xs text-secondary">{{ trans_choice('After :count consecutive failure|After :count consecutive failures', $website->health_failure_threshold, ['count' => $website->health_failure_threshold]) }}</dd>
            </div>
        @endif
        <div>
            <dt class="font-semibold text-primary">{{ __('Retained releases') }}</dt>
            <dd class="mt-1 text-xs text-secondary">{{ $website->release_retention }}</dd>
        </div>
        </dl>
    </x-ui.card>

    @if ($website->health_status === \App\Models\Website::HEALTH_UNHEALTHY && $website->health_last_error)
        <x-ui.alert tone="danger" class="mt-4">
            <strong>{{ __('Health check failed:') }}</strong> {{ $website->health_last_error }}
        </x-ui.alert>
    @endif

    @php
        $websiteOperationsNeedAttention = $website->provisioning_status !== \App\Models\Website::STATUS_ACTIVE
            || $website->previous_server_id !== null;
    @endphp
    <details id="website-operations" class="group ui-card mt-6 overflow-hidden" @if ($websiteOperationsNeedAttention) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-primary [&::-webkit-details-marker]:hidden">
            <span>
                <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Operations') }}</span>
                <span class="mt-1 block text-lg">{{ __('Provisioning timeline') }}</span>
                <span class="mt-1 block text-sm font-normal text-secondary">
                    {{ str($website->provisioning_status ?? 'unknown')->replace('_', ' ')->headline() }}
                    @if ($website->previous_server_id)
                        · {{ __('Previous placement cleanup pending') }}
                    @endif
                </span>
            </span>
            <span class="text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <div class="space-y-6 border-t border-primary p-5">
            <livewire:website-setup :model="$website" />
            <livewire:website-provisioning-log :website="$website" />
        </div>
    </details>

    <section class="mt-8" aria-labelledby="health-history-heading">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="health-history-heading" class="text-2xl font-bold text-primary">{{ __('Recent health checks') }}</h2>
                <p class="mt-1 text-sm text-secondary">
                    {{ __('Accepted manual and automatic results are retained for the latest 100 checks. This page shows the newest 20.') }}
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <x-ui.button :href="route('websites.health-checks.index', $website)" variant="secondary">{{ __('View all health checks') }}</x-ui.button>
                @if ($healthChecks->isNotEmpty())
                    <x-ui.button :href="route('websites.health-checks.export', $website)" variant="secondary">{{ __('Export health history') }}</x-ui.button>
                @endif
            </div>
        </div>

        <x-ui.insights
            id="website-health-insights"
            class="mt-4"
            :summary="trans_choice(':count retained check|:count retained checks', $healthMetrics['total'], ['count' => $healthMetrics['total']])"
        >
            <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.stat :label="__('Retained checks')" :value="$healthMetrics['total']" :description="__('Newest :limit maximum', ['limit' => \App\Models\WebsiteHealthCheck::MAX_PER_WEBSITE])" />
                <x-ui.stat :label="__('Observed check success')" :value="$healthMetrics['success_rate'] !== null ? $healthMetrics['success_rate'].'%' : __('Not available')" :description="trans_choice(':count successful check|:count successful checks', $healthMetrics['successful'], ['count' => $healthMetrics['successful']])" />
                <x-ui.stat :label="__('Median healthy response')" :value="$healthMetrics['median_healthy_duration_ms'] !== null ? $healthMetrics['median_healthy_duration_ms'].' ms' : __('Not recorded')" :description="__('Failed and unreported timings are excluded.')" />
                <x-ui.stat :label="__('Current failure streak')" :value="$healthMetrics['failure_streak']" :description="trans_choice(':count consecutive failed check|:count consecutive failed checks', $healthMetrics['failure_streak'], ['count' => $healthMetrics['failure_streak']])" />
            </dl>
        </x-ui.insights>
        <p class="mt-3 text-xs text-secondary">
            {{ __('These figures summarize retained observations and are not an SLA uptime calculation.') }}
        </p>

        @if ($healthChecks->isEmpty())
            <x-ui.empty-state class="mt-4" :title="__('No health checks have been recorded yet.')" />
        @else
            <details id="website-health-history" class="group ui-card mt-4 overflow-hidden">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 font-bold text-primary [&::-webkit-details-marker]:hidden">
                    <span>{{ __('Latest check results') }}</span>
                    <span class="text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
                </summary>
                <div class="divide-y divide-primary border-t border-primary">
                    @foreach ($healthChecks as $check)
                        @include('scenes.websites._health-check-card', ['check' => $check])
                    @endforeach
                </div>
            </details>
        @endif
    </section>

    @php
        $runtimeLogsNeedAttention = $runtimeLogs->contains(fn ($snapshot) => in_array($snapshot?->status, [
            \App\Models\WebsiteLogSnapshot::STATUS_QUEUED,
            \App\Models\WebsiteLogSnapshot::STATUS_REFRESHING,
            \App\Models\WebsiteLogSnapshot::STATUS_FAILED,
        ], true));
    @endphp
    <details id="website-runtime-logs" class="group ui-card mt-6 overflow-hidden" @if ($runtimeLogsNeedAttention) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-primary [&::-webkit-details-marker]:hidden">
            <span>
                <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Runtime') }}</span>
                <span class="mt-1 block text-lg">{{ __('Live log snapshots') }}</span>
                <span class="mt-1 block text-sm font-normal text-secondary">{{ __('Application and access output with bounded retention.') }}</span>
            </span>
            <span class="text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <section class="border-t border-primary p-5" id="runtime-logs" x-data="{ logType: 'application' }">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Runtime') }}</p>
                <h2 class="mt-1 text-xl font-black text-primary">{{ __('Live log snapshots') }}</h2>
                <p class="mt-1 text-sm text-secondary">{{ __('Fetch the latest encrypted application or per-site access output without exposing another website’s traffic.') }}</p>
            </div>
            <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Log type') }}">
                @foreach (\App\Models\WebsiteLogSnapshot::TYPES as $type)
                    <button type="button" class="button button--secondary" role="tab" @click="logType='{{ $type }}'">{{ ucfirst($type) }}</button>
                @endforeach
            </div>
        </div>
        @foreach (\App\Models\WebsiteLogSnapshot::TYPES as $type)
            @php
                $snapshot = $runtimeLogs->get($type);
            @endphp
            <div x-show="logType === '{{ $type }}'" class="mt-4" data-runtime-log-console data-refresh-url="{{ route('websites.runtime-logs.refresh', [$website, $type]) }}" data-report-url="{{ route('websites.runtime-logs.show', [$website, $type]) }}">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-secondary" data-log-status>{{ $snapshot?->refreshed_at ? __('Updated :time', ['time' => $snapshot->refreshed_at->diffForHumans()]) : __('Not collected yet') }} · {{ ucfirst($snapshot?->status ?? 'idle') }}</p>
                    <form method="POST" action="{{ route('websites.runtime-logs.refresh', [$website, $type]) }}">
                        @csrf
                        <x-ui.button type="submit" variant="primary">{{ __('Refresh :type log', ['type' => $type]) }}</x-ui.button>
                    </form>
                </div>
                <div class="mb-3 grid gap-3 sm:grid-cols-[1fr_12rem_auto]">
                    <input type="search" data-log-search class="input secondary min-h-[2.75rem] rounded-lg" placeholder="{{ __('Search log lines') }}">
                    <select data-log-level class="input secondary min-h-[2.75rem] rounded-lg">
                        <option value="">{{ __('All levels') }}</option>
                        <option value="emergency">Emergency</option>
                        <option value="error">Error</option>
                        <option value="warning">Warning</option>
                        <option value="info">Info</option>
                        <option value="debug">Debug</option>
                    </select>
                    <label class="flex min-h-[2.75rem] items-center gap-2 rounded-lg border border-primary px-3 text-sm text-primary">
                        <input type="checkbox" data-log-live>
                        {{ __('Live') }}
                    </label>
                </div>
                @if($snapshot?->error)
                    <x-ui.alert tone="danger" class="mb-3">{{ $snapshot->error }}</x-ui.alert>
                @endif
                <pre data-log-output class="max-h-[32rem] overflow-auto whitespace-pre-wrap break-words rounded-xl bg-slate-950 p-5 font-mono text-xs leading-5 text-slate-100">{{ $snapshot?->log ?: __('No log output captured.') }}</pre>
            </div>
        @endforeach
        @if ($canUpdateWebsite)
            <div class="mt-4 border-t border-primary pt-4">
                <x-ui.button
                    :href="$logRetentionDialogUrl"
                    data-modal-trigger="{{ $logRetentionDialogId }}"
                    aria-controls="{{ $logRetentionDialogId }}"
                    aria-expanded="{{ $logRetentionDialogOpen ? 'true' : 'false' }}"
                    variant="secondary"
                >{{ __('Configure retention') }}</x-ui.button>
            </div>
        @endif
        </section>
    </details>
    @if ($canUpdateWebsite)
        <x-scenes.websites.log-retention-dialog
            :website="$website"
            :open="$logRetentionDialogOpen"
        />
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Quick Actions
     ! ------------------------------------------------------------
     !-->
    <section class="mt-8" aria-labelledby="attached-repositories-heading">
        <x-ui.card class="p-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Deployments') }}</p>
                    <h2 id="attached-repositories-heading" class="mt-1 text-lg font-bold text-primary">{{ __('Attached Repositories') }}</h2>
                </div>
                <x-ui.button
                    :href="$repositoryCreateUrl"
                    data-modal-trigger="repository-create-dialog"
                    data-modal-content-url="{{ $repositoryCreateContentUrl }}"
                    aria-controls="repository-create-dialog"
                    aria-expanded="{{ $repositoryCreateOpen ? 'true' : 'false' }}"
                    variant="ghost"
                >{{ __('Add repository') }}</x-ui.button>
            </div>
            <ul role="list" class="mt-4 divide-y divide-primary">
                @forelse($repositories as $repository)
                    <li>
                        <a href="{{ route('repositories.show', $repository) }}" class="flex items-center gap-4 py-3 hover:bg-secondary">
                            <x-avatar :name="$repository->name" class="h-8 w-8 shrink-0 rounded-full text-xs" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-ternary">{{ $repository->name }}</span>
                                <span class="block truncate text-sm text-secondary">{{ $repository->url }}</span>
                            </span>
                            @if ($repository->latestBuild)
                                <x-ui.badge>{{ str($repository->latestBuild->status)->replace('_', ' ') }}</x-ui.badge>
                            @else
                                <x-ui.badge>{{ __('Not deployed') }}</x-ui.badge>
                            @endif
                        </a>
                    </li>
                @empty
                    <li class="pt-3">
                        <x-ui.alert tone="info" role="status">{{ __('No repositories attached to website') }}</x-ui.alert>
                    </li>
                @endforelse
            </ul>
            @if ($repositories->hasPages())
                <div class="mt-4 border-t border-primary pt-4">{{ $repositories->links() }}</div>
            @endif
        </x-ui.card>
    </section>

    <x-scenes.websites.edit-dialog
        :website="$website"
        :servers="$servers"
        :open="$websiteEditOpen"
        :cancel-url="$websitePageUrl"
        :content-url="$websiteEditContentUrl"
    />
</x-layouts.app>
