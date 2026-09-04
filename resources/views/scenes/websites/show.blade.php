<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Show passwords
     ! ------------------------------------------------------------
     !-->
    @if(session()->has("website:{$website->id}:mysql_password"))
        <div class="my-4">
            <x-alerts.info>
                <x-slot name="title">
                    The root MYSQL password is: <b class="font-bold">
                        {{ session()->get("website:{$website->id}:mysql_password") }}
                    </b>
                    <br>
                    This will only be shown once, so please save these passwords somewhere safe.
                </x-slot>
            </x-alerts.info>
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
            <a href="{{ route('builds.index', ['website_id' => $website->id]) }}" class="button secondary">
                {{ __('Deployment history') }}
            </a>

            @if ($website->health_check_enabled
                && $website->provisioning_status === \App\Models\Website::STATUS_ACTIVE
                && $website->server?->provisioning_status === \App\Models\Server::STATUS_ACTIVE)
                <form method="POST" action="{{ route('websites.health.check', $website) }}">
                    @csrf
                    <button type="submit" class="button primary">
                        {{ __('Check health now') }}
                    </button>
                </form>
            @endif

            <a href="{{ route('websites.edit', $website) }}" class="button primary">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit Website') }}
            </a>

            <x-dialogs.delete
                id="delete-website"
                :route="route('websites.destroy', $website)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this website?')"
            ></x-dialogs.delete>

            <button type="submit" class="button primary" onclick="document.getElementById('delete-website').showModal()">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete Website') }}
            </button>

        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if ($website->provisioning_status === \App\Models\Website::STATUS_FAILED)
        <div class="my-4 rounded border border-red-300 bg-red-50 p-4 text-red-700">
            <p class="font-semibold">{{ __('Website provisioning failed') }}</p>
            <p class="text-sm">{{ $website->provisioning_error }}</p>
            @error('retry')
                <p class="mt-2 text-sm font-semibold">{{ $message }}</p>
            @enderror
            <form method="POST" action="{{ route('websites.provisioning.retry', $website) }}" class="mt-3">
                @csrf
                <button type="submit" class="button primary">{{ __('Retry provisioning') }}</button>
            </form>
        </div>
    @endif

    @if ($website->previous_server_id)
        <div class="my-4 rounded border border-amber-300 bg-amber-50 p-4 text-amber-800">
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
                    <button type="submit" class="button primary">{{ __('Retry cleanup') }}</button>
                </form>
            @endif
        </div>
    @endif

    <!--
     ! ------------------------------------------------------------
     ! Website information
     ! ------------------------------------------------------------
     !-->
    <div class="flex items-center mt-4 text-gray-500">
        <div class="flex items-center mr-6">
            <svg class="mr-2 w-4 h-4 text-gray-400">
                <use xlink:href="/assets/images/icons.svg#external-link"></use>
            </svg>
            <span class="mr-1 text-primary">
                {{ __('URL') }}
            </span>
            <div class="text-secondary">
                <div class="-mx-1 px-1 rounded-sm cursor-pointer">
                    {{ $website->url }}
                </div>
            </div>
        </div>
        <div class="flex items-center mr-6">
            <span class="mr-1 text-primary">{{ __('Deployment health check') }}</span>
            <span class="text-secondary">
                {{ $website->health_check_enabled ? $website->health_check_path : __('Disabled') }}
            </span>
        </div>
        @if ($website->health_check_enabled)
            <div class="flex items-center mr-6">
                <span class="mr-1 text-primary">{{ __('Current health') }}</span>
                <span @class([
                    'font-medium',
                    'text-green-600' => $website->health_status === \App\Models\Website::HEALTH_HEALTHY,
                    'text-red-600' => $website->health_status === \App\Models\Website::HEALTH_UNHEALTHY,
                    'text-secondary' => $website->health_status === \App\Models\Website::HEALTH_UNKNOWN,
                ])>{{ str($website->health_status)->title() }}</span>
                @if ($website->health_last_checked_at)
                    <span class="ml-1 text-secondary">({{ $website->health_last_checked_at->diffForHumans() }})</span>
                @endif
            </div>
            <div class="flex items-center mr-6">
                <span class="mr-1 text-primary">{{ __('Automatic monitoring') }}</span>
                <span @class([
                    'font-medium',
                    'text-green-600' => $website->health_monitoring_enabled,
                    'text-amber-700' => ! $website->health_monitoring_enabled,
                ])>
                    {{ $website->health_monitoring_enabled ? __('Enabled') : __('Paused') }}
                </span>
                <span class="ml-1 text-secondary">
                    ({{ trans_choice('every :count minute|every :count minutes', $website->health_check_interval_minutes, ['count' => $website->health_check_interval_minutes]) }})
                </span>
            </div>
            <div class="flex items-center mr-6">
                <span class="mr-1 text-primary">{{ __('Outage confirmation') }}</span>
                <span class="text-secondary">
                    {{ trans_choice('After :count consecutive failure|After :count consecutive failures', $website->health_failure_threshold, ['count' => $website->health_failure_threshold]) }}
                </span>
            </div>
        @endif
        <div class="flex items-center mr-6">
            <span class="mr-1 text-primary">{{ __('Retained releases') }}</span>
            <span class="text-secondary">{{ $website->release_retention }}</span>
        </div>
    </div>

    @if ($website->health_status === \App\Models\Website::HEALTH_UNHEALTHY && $website->health_last_error)
        <div class="mt-4 rounded border border-red-300 bg-red-50 p-4 text-red-800">
            <strong>{{ __('Health check failed:') }}</strong> {{ $website->health_last_error }}
        </div>
    @endif

    <section class="mt-8" aria-labelledby="health-history-heading">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="health-history-heading" class="text-2xl font-bold text-primary">{{ __('Recent health checks') }}</h2>
                <p class="mt-1 text-sm text-secondary">
                    {{ __('Accepted manual and automatic results are retained for the latest 100 checks. This page shows the newest 20.') }}
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('websites.health-checks.index', $website) }}" class="button primary">{{ __('View all health checks') }}</a>
                @if ($healthChecks->isNotEmpty())
                    <a href="{{ route('websites.health-checks.export', $website) }}" class="button primary">{{ __('Export health history') }}</a>
                @endif
            </div>
        </div>

        <dl class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-primary bg-primary p-4">
                <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Retained checks') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-primary">{{ $healthMetrics['total'] }}</dd>
                <dd class="mt-1 text-xs text-secondary">{{ __('Newest :limit maximum', ['limit' => \App\Models\WebsiteHealthCheck::MAX_PER_WEBSITE]) }}</dd>
            </div>
            <div class="rounded-lg border border-primary bg-primary p-4">
                <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Observed check success') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-primary">
                    {{ $healthMetrics['success_rate'] !== null ? $healthMetrics['success_rate'].'%' : __('Not available') }}
                </dd>
                <dd class="mt-1 text-xs text-secondary">
                    {{ trans_choice(':count successful check|:count successful checks', $healthMetrics['successful'], ['count' => $healthMetrics['successful']]) }}
                </dd>
            </div>
            <div class="rounded-lg border border-primary bg-primary p-4">
                <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Median healthy response') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-primary">
                    {{ $healthMetrics['median_healthy_duration_ms'] !== null ? $healthMetrics['median_healthy_duration_ms'].' ms' : __('Not recorded') }}
                </dd>
                <dd class="mt-1 text-xs text-secondary">{{ __('Failed and unreported timings are excluded.') }}</dd>
            </div>
            <div class="rounded-lg border border-primary bg-primary p-4">
                <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Current failure streak') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-primary">{{ $healthMetrics['failure_streak'] }}</dd>
                <dd class="mt-1 text-xs text-secondary">
                    {{ trans_choice(':count consecutive failed check|:count consecutive failed checks', $healthMetrics['failure_streak'], ['count' => $healthMetrics['failure_streak']]) }}
                </dd>
            </div>
        </dl>
        <p class="mt-3 text-xs text-secondary">
            {{ __('These figures summarize retained observations and are not an SLA uptime calculation.') }}
        </p>

        @if ($healthChecks->isEmpty())
            <div class="mt-4 rounded-lg border border-primary bg-primary p-5 text-sm text-secondary">
                {{ __('No health checks have been recorded yet.') }}
            </div>
        @else
            <div class="mt-4 overflow-x-auto rounded-lg border border-primary">
                <table class="min-w-full divide-y divide-primary bg-primary text-sm">
                    <thead>
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Result') }}</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Source') }}</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Response') }}</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-secondary">{{ __('Endpoint') }}</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold text-secondary">{{ __('Checked') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-primary">
                        @foreach ($healthChecks as $check)
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <span @class([
                                        'rounded-full px-2 py-1 text-xs font-semibold uppercase',
                                        'bg-green-100 text-green-700' => $check->successful,
                                        'bg-red-100 text-red-700' => ! $check->successful,
                                    ])>{{ $check->successful ? __('Healthy') : __('Failed') }}</span>
                                    @if ($check->error)
                                        <p class="mt-2 max-w-md whitespace-pre-wrap break-words text-xs text-red-700">{{ $check->error }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-primary">{{ str($check->source)->title() }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-primary">
                                    @if ($check->http_status)
                                        {{ __('HTTP :status', ['status' => $check->http_status]) }}
                                    @else
                                        {{ __('No status') }}
                                    @endif
                                    <span class="block text-xs text-secondary">
                                        {{ $check->duration_ms !== null ? __(':duration ms', ['duration' => $check->duration_ms]) : __('Duration unavailable') }}
                                    </span>
                                </td>
                                <td class="max-w-md break-all px-4 py-3 font-mono text-xs text-primary">{{ $check->endpoint }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-secondary" title="{{ $check->checked_at }}">
                                    {{ $check->checked_at->diffForHumans() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <livewire:website-provisioning-log :website="$website" />

    <!--
     ! ------------------------------------------------------------
     ! Quick Actions
     ! ------------------------------------------------------------
     !-->
    <div class="py-4 grid grid-cols-3 gap-6">
        <div class="col-span-3 lg:col-span-1 space-y-4">
            <div class="p-4 bg-primary rounded-lg border shadow-md border-primary">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold leading-none text-primary">
                        {{ __('Attached Repositories') }}
                    </h3>
                    <a href="{{ route('repositories.create') }}" class="text-ternary text-xs font-semibold underline">
                        Add Repo
                    </a>
                </div>
                <div class="flow-root">
                    <ul role="list" class="divide-y divide-primary">
                        @forelse($repositories as $repository)
                            <a href="{{ route('repositories.show', $repository) }}" class="py-3">
                                <div class="flex items-center space-x-4">
                                    <div class="flex-shrink-0">
                                        <x-avatar :name="$repository->name" class="h-8 w-8 rounded-full text-xs" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-ternary truncate">
                                            {{ $repository->name }}
                                        </p>
                                        <p class="text-sm truncate text-secondary">
                                            {{ $repository->url }}
                                        </p>
                                    </div>
                                    <div class="inline-flex items-center text-md font-semibold text-primary dark:text-white uppercase">
                                        {{ $repository->latestBuild?->status ?? __('Not deployed') }}
                                    </div>
                                </div>
                            </a>
                        @empty
                            <x-alerts.info :title="__('No repositories attached to server')"></x-alerts.info>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!--
     ! ------------------------------------------------------------
     ! Website Setup
     ! ------------------------------------------------------------
     !-->
    <livewire:website-setup :model="$website" />

</x-layouts.app>
