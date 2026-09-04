<x-layouts.app>

    <!--
     ! ------------------------------------------------------------
     ! Breadcrumbs
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.breadcrumbs
        :title="__('Back to Repositories')"
        :route="route('repositories.index')"
    ></x-layouts.partials.breadcrumbs>

    <!--
     ! ------------------------------------------------------------
     ! Heading
     ! ------------------------------------------------------------
     !-->
    <x-layouts.partials.heading
        :title="$repository->name"
        :description="$repository->description"
    >
        <x-slot:buttons>

            <form method="POST" action="{{ route('repositories.deploy', $repository) }}">
                @csrf
                <button type="submit" class="button primary" @disabled($deploymentInProgress || ! $deploymentReady)>
                    <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                        <use xlink:href="/assets/images/icons.svg#cloud-upload"></use>
                    </svg>
                    {{ ! $deploymentReady ? __('Deployment unavailable') : ($deploymentInProgress ? __('Deployment in progress') : __('Deploy')) }}
                </button>
            </form>

            <a href="{{ route('repositories.edit', $repository) }}" class="button primary">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit') }}
            </a>

            <x-dialogs.delete
                id="delete-repository"
                :route="route('repositories.destroy', $repository)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this repository?')"
            ></x-dialogs.delete>

            <button type="submit" class="button primary" onclick="document.getElementById('delete-repository').showModal()">
                <svg class="w-4 h-4 text-secondary stroke-2 mr-2">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete') }}
            </button>

        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if (! $deploymentReady)
        <div class="my-4 rounded border border-amber-300 bg-amber-50 p-4 text-amber-800">
            {{ __('The linked website and server must both be active before this repository can be deployed.') }}
        </div>
    @endif

    @php($oneTimeWebhookSecret = session("repository:{$repository->id}:webhook_secret"))
    <section id="deployment-webhook" class="my-6 rounded-lg border border-primary bg-primary p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold text-primary">{{ __('Automatic push deployments') }}</h2>
                <p class="mt-1 text-sm text-secondary">
                    {{ __('Deploy the configured branch after an authenticated source-control push.') }}
                </p>
            </div>
            <span @class([
                'rounded-full px-3 py-1 text-xs font-semibold uppercase',
                'bg-green-100 text-green-700' => $repository->webhook_enabled,
                'bg-gray-100 text-gray-700' => ! $repository->webhook_enabled,
            ])>{{ $repository->webhook_enabled ? __('Enabled') : __('Disabled') }}</span>
        </div>

        <div class="mt-4">
            <label for="webhook-url" class="block text-xs font-semibold uppercase text-secondary">{{ __('Payload URL') }}</label>
            <input
                id="webhook-url"
                type="text"
                readonly
                value="{{ route('webhooks.repositories.receive', $repository) }}"
                class="input secondary mt-1 w-full rounded font-mono text-sm"
            >
        </div>

        @if ($oneTimeWebhookSecret)
            <div class="mt-4 rounded border border-amber-300 bg-amber-50 p-4 text-amber-900">
                <p class="font-semibold">{{ __('Copy this webhook secret now. It will not be shown again.') }}</p>
                <input
                    type="text"
                    readonly
                    value="{{ $oneTimeWebhookSecret }}"
                    class="input secondary mt-2 w-full rounded font-mono text-sm"
                >
            </div>
        @endif

        <div class="mt-4 text-sm text-secondary">
            @if ($repository->provider->provider === \App\Models\Provider::TYPE_GITHUB)
                <p>{{ __('Create a GitHub push webhook using JSON content, the payload URL above, and the generated secret.') }}</p>
            @elseif ($repository->provider->provider === \App\Models\Provider::TYPE_BITBUCKET)
                <p>{{ __('Create a Bitbucket repository push webhook using the payload URL above and the generated secret.') }}</p>
            @else
                <p>{{ __('Create a GitLab push webhook, copy its whsec_ signing token, and save that token below.') }}</p>
            @endif
            <p class="mt-1">{{ __('Only pushes to :branch deploy. Duplicate deliveries are ignored.', ['branch' => $repository->branch]) }}</p>
            @if ($repository->webhook_pending)
                <p class="mt-2 font-medium text-amber-700">{{ __('A newer push is waiting for the active deployment to finish.') }}</p>
            @elseif ($repository->webhook_last_received_at)
                <p class="mt-2">{{ __('Last accepted delivery: :time', ['time' => $repository->webhook_last_received_at->diffForHumans()]) }}</p>
            @endif
        </div>

        <div class="mt-5 flex flex-wrap gap-3">
            <form method="POST" action="{{ route('repositories.webhook.store', $repository) }}" class="flex flex-wrap gap-3">
                @csrf
                @if ($repository->provider->provider === \App\Models\Provider::TYPE_GITLAB)
                    <div>
                        <label for="signing_token" class="sr-only">{{ __('GitLab signing token') }}</label>
                        <input
                            id="signing_token"
                            name="signing_token"
                            type="password"
                            required
                            autocomplete="off"
                            placeholder="whsec_…"
                            class="input secondary rounded"
                        >
                        <x-forms.errors name="signing_token" />
                    </div>
                @endif
                <button type="submit" class="button primary">
                    {{ $repository->webhook_enabled ? __('Rotate webhook secret') : __('Enable webhook') }}
                </button>
            </form>

            @if ($repository->webhook_enabled)
                <form method="POST" action="{{ route('repositories.webhook.destroy', $repository) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="button primary">{{ __('Disable webhook') }}</button>
                </form>
            @endif
        </div>

        <div class="mt-8 border-t border-primary pt-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-primary">{{ __('Webhook delivery history') }}</h3>
                    <p class="mt-1 text-sm text-secondary">{{ __('Review accepted deliveries without exposing webhook payloads or credentials.') }}</p>
                </div>
                <form method="GET" action="{{ route('repositories.show', $repository) }}#webhook-deliveries" class="flex flex-wrap items-end gap-2">
                    <div>
                        <label for="delivery_status" class="block text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</label>
                        <select id="delivery_status" name="delivery_status" class="input secondary mt-1 rounded">
                            <option value="">{{ __('All statuses') }}</option>
                            @foreach ($deliveryStatuses as $status)
                                <option value="{{ $status }}" @selected($deliveryFilters['delivery_status'] === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="delivery_date_from" class="block text-xs font-semibold uppercase text-secondary">{{ __('Received from') }}</label>
                        <input
                            id="delivery_date_from"
                            name="delivery_date_from"
                            type="date"
                            value="{{ $deliveryFilters['delivery_date_from'] }}"
                            class="input secondary mt-1 rounded"
                        >
                    </div>
                    <div>
                        <label for="delivery_date_to" class="block text-xs font-semibold uppercase text-secondary">{{ __('Received through') }}</label>
                        <input
                            id="delivery_date_to"
                            name="delivery_date_to"
                            type="date"
                            value="{{ $deliveryFilters['delivery_date_to'] }}"
                            class="input secondary mt-1 rounded"
                        >
                    </div>
                    <button type="submit" class="button primary">{{ __('Apply') }}</button>
                    @if (array_filter($deliveryFilters, fn ($value) => $value !== null))
                        <a href="{{ route('repositories.show', $repository) }}#webhook-deliveries" class="button tertiary">{{ __('Clear') }}</a>
                    @endif
                    <a href="{{ route('repositories.webhook-deliveries.export', [$repository, ...array_filter($deliveryFilters, fn ($value) => $value !== null)]) }}" class="button tertiary">{{ __('Export CSV') }}</a>
                </form>
            </div>

            <div id="webhook-deliveries" class="mt-4">
                @if ($webhookDeliveries->isEmpty())
                    <p class="rounded border border-primary p-4 text-sm text-secondary">
                        {{ array_filter($deliveryFilters, fn ($value) => $value !== null) ? __('No webhook deliveries match these filters.') : __('No webhook deliveries have been accepted yet.') }}
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-primary border-y border-primary">
                            <thead>
                                <tr>
                                    <th class="py-3 pr-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Delivery') }}</th>
                                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Revision') }}</th>
                                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</th>
                                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase text-secondary">{{ __('Result') }}</th>
                                    <th class="py-3 pl-3 text-right text-xs font-semibold uppercase text-secondary">{{ __('Received') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-primary">
                                @foreach ($webhookDeliveries as $delivery)
                                    <tr>
                                        <td class="py-3 pr-3 text-sm">
                                            <span class="font-mono text-primary">{{ $delivery->delivery_id }}</span>
                                            @if ($delivery->commit_message)
                                                <p class="mt-1 max-w-md truncate text-secondary" title="{{ $delivery->commit_message }}">{{ $delivery->commit_message }}</p>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-3 text-sm font-mono text-secondary">
                                            @if ($delivery->revision)
                                                @if ($revisionUrl = $repository->revisionUrl($delivery->revision))
                                                    <a href="{{ $revisionUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ str($delivery->revision)->take(12) }}</a>
                                                @else
                                                    {{ str($delivery->revision)->take(12) }}
                                                @endif
                                            @else
                                                &mdash;
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-3 text-sm">
                                            <span @class([
                                                'rounded-full px-2 py-1 text-xs font-semibold uppercase',
                                                'bg-green-100 text-green-700' => $delivery->status === \App\Models\RepositoryWebhookDelivery::STATUS_QUEUED,
                                                'bg-amber-100 text-amber-700' => $delivery->status === \App\Models\RepositoryWebhookDelivery::STATUS_PENDING,
                                                'bg-red-100 text-red-700' => $delivery->status === \App\Models\RepositoryWebhookDelivery::STATUS_UNAVAILABLE,
                                                'bg-gray-100 text-gray-700' => in_array($delivery->status, [
                                                    \App\Models\RepositoryWebhookDelivery::STATUS_SUPERSEDED,
                                                    \App\Models\RepositoryWebhookDelivery::STATUS_RECEIVED,
                                                ], true),
                                            ])>{{ str($delivery->status)->replace('_', ' ') }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-3 text-sm text-secondary">
                                            @if ($delivery->build)
                                                <a href="{{ route('builds.show', $delivery->build) }}" class="font-medium text-primary hover:underline">{{ __('Build #:id', ['id' => $delivery->build->id]) }}</a>
                                            @elseif ($delivery->status === \App\Models\RepositoryWebhookDelivery::STATUS_SUPERSEDED)
                                                {{ __('Replaced by a newer push') }}
                                            @elseif ($delivery->status === \App\Models\RepositoryWebhookDelivery::STATUS_PENDING)
                                                {{ __('Waiting for active deployment') }}
                                            @elseif ($delivery->status === \App\Models\RepositoryWebhookDelivery::STATUS_UNAVAILABLE)
                                                {{ __('Deployment unavailable') }}
                                            @else
                                                &mdash;
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap py-3 pl-3 text-right text-sm text-secondary" title="{{ $delivery->created_at }}">
                                            {{ $delivery->created_at->diffForHumans() }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $webhookDeliveries->links() }}</div>
                @endif
            </div>
        </div>
    </section>

    <!--
     ! ------------------------------------------------------------
     ! Repository information
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
                    {{ $repository->url }}
                </div>
            </div>
        </div>
        <div class="flex items-center mr-6">
            <svg class="mr-2 w-4 h-4 text-gray-400">
                <use xlink:href="/assets/images/icons.svg#external-link"></use>
            </svg>
            <span class="mr-1 text-primary">{{ __('Branch') }}</span>
            <span class="text-secondary">{{ $repository->branch }}</span>
        </div>
        @if ($repository->build_commands)
            <div class="flex items-center mr-6">
                <span class="text-secondary">{{ __('Build hook configured') }}</span>
            </div>
        @endif
        @if ($repository->post_deployment_commands)
            <div class="flex items-center mr-6">
                <span class="text-secondary">{{ __('Post-deployment hook configured') }}</span>
            </div>
        @endif
    </div>

    <div class="col-span-3">
        <livewire:repository-setup :model="$repository"></livewire:repository-setup>
    </div>

    <section class="mt-10" aria-labelledby="deployment-insights-heading">
        <div>
            <h2 id="deployment-insights-heading" class="text-2xl font-bold text-primary">{{ __('Deployment insights') }}</h2>
            <p class="mt-1 text-sm text-secondary">
                {{ __('Outcome totals cover all recorded deployments. Median duration uses up to the 20 most recent deployments with valid start and finish times.') }}
            </p>
        </div>
        <dl class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <a href="{{ route('builds.index', ['repository_id' => $repository->id]) }}" class="rounded-lg border border-primary bg-primary p-4 hover:bg-secondary">
                <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Total deployments') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-primary">{{ $deploymentMetrics['total'] }}</dd>
            </a>
            <a href="{{ route('builds.index', ['repository_id' => $repository->id, 'status' => \App\Models\Build::STATUS_SUCCEEDED]) }}" class="rounded-lg border border-primary bg-primary p-4 hover:bg-secondary">
                <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Succeeded') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-green-600">{{ $deploymentMetrics['succeeded'] }}</dd>
            </a>
            <a href="{{ route('builds.index', ['repository_id' => $repository->id, 'status' => \App\Models\Build::STATUS_FAILED]) }}" class="rounded-lg border border-primary bg-primary p-4 hover:bg-secondary">
                <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Failed') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-red-600">{{ $deploymentMetrics['failed'] }}</dd>
            </a>
            <div class="rounded-lg border border-primary bg-primary p-4">
                <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Completed-run success rate') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-primary">
                    {{ $deploymentMetrics['success_rate'] !== null ? $deploymentMetrics['success_rate'].'%' : __('Not available') }}
                </dd>
                <p class="mt-1 text-xs text-secondary">{{ __('Canceled and active runs are excluded.') }}</p>
            </div>
            <div class="rounded-lg border border-primary bg-primary p-4">
                <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Recent median duration') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-primary">
                    {{ $deploymentMetrics['median_duration_seconds'] !== null ? \App\Models\Build::formatDuration($deploymentMetrics['median_duration_seconds']) : __('Not recorded') }}
                </dd>
                <p class="mt-1 text-xs text-secondary">
                    {{ trans_choice(':count timed deployment|:count timed deployments', $deploymentMetrics['duration_sample_size'], ['count' => $deploymentMetrics['duration_sample_size']]) }}
                </p>
            </div>
        </dl>
    </section>

    <section class="mt-10">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-2xl font-bold text-primary">{{ __('Deployment history') }}</h2>
            <a href="{{ route('builds.index', ['repository_id' => $repository->id]) }}" class="button primary">
                {{ __('View all deployments') }}
            </a>
        </div>
        @forelse ($builds as $build)
            <div class="mb-3 flex items-center justify-between rounded-lg border border-primary bg-primary p-4">
                <div>
                    <a href="{{ route('builds.show', $build) }}" class="font-medium text-primary hover:underline">
                        {{ __('Build #:id', ['id' => $build->id]) }}
                    </a>
                    <p class="text-sm text-secondary">
                        {{ $build->created_at->diffForHumans() }}
                        &middot; {{ ucfirst($build->trigger_source) }}
                        @if ($build->redeployed_from_build_id)
                            {{ __('of build #:id', ['id' => $build->redeployed_from_build_id]) }}
                        @endif
                        @if ($build->revision)
                            &middot;
                            @if ($revisionUrl = $repository->revisionUrl($build->revision))
                                <a href="{{ $revisionUrl }}" target="_blank" rel="noopener noreferrer" class="font-mono hover:underline">{{ $build->shortRevision() }}</a>
                            @else
                                <span class="font-mono">{{ $build->shortRevision() }}</span>
                            @endif
                        @endif
                        @if ($build->failure_message)
                            &middot; {{ $build->failure_message }}
                        @endif
                    </p>
                </div>
                <span @class([
                    'rounded-full px-3 py-1 text-xs font-semibold uppercase',
                    'bg-green-100 text-green-700' => $build->status === \App\Models\Build::STATUS_SUCCEEDED,
                    'bg-red-100 text-red-700' => $build->status === \App\Models\Build::STATUS_FAILED,
                    'bg-amber-100 text-amber-700' => in_array($build->status, [
                        \App\Models\Build::STATUS_CANCELED,
                        \App\Models\Build::STATUS_TIMING_OUT,
                    ], true),
                    'bg-blue-100 text-blue-700' => in_array($build->status, [\App\Models\Build::STATUS_DEPLOYING, \App\Models\Build::STATUS_RUNNING]),
                    'bg-gray-100 text-gray-700' => $build->status === \App\Models\Build::STATUS_QUEUED,
                ])>{{ str($build->status)->replace('_', ' ') }}</span>
            </div>
        @empty
            <x-lists.empty
                :title="__('No deployments yet')"
                :description="__('Deploy this repository to create its first build.')"
            />
        @endforelse
    </section>

</x-layouts.app>
