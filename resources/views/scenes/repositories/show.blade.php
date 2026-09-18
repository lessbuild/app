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
                <x-ui.button type="submit" variant="primary" :disabled="$deploymentInProgress || ! $deploymentReady || $deploymentPlanBlocked">
                    <svg class="h-4 w-4" aria-hidden="true">
                        <use xlink:href="/assets/images/icons.svg#cloud-upload"></use>
                    </svg>
                    {{ ! $deploymentReady || $deploymentPlanBlocked ? __('Deployment unavailable') : ($deploymentInProgress ? __('Deployment in progress') : __('Deploy')) }}
                </x-ui.button>
            </form>

            <x-ui.button :href="route('repositories.edit', $repository)" variant="secondary">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit') }}
            </x-ui.button>

            <x-dialogs.delete
                id="delete-repository"
                :route="route('repositories.destroy', $repository)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this repository?')"
            ></x-dialogs.delete>

            <button type="button" class="button button--danger" onclick="document.getElementById('delete-repository').showModal()">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete') }}
            </button>

        </x-slot:buttons>
    </x-layouts.partials.heading>

    @if (! $deploymentReady)
        <x-ui.alert tone="warning" class="my-4">
            {{ __('The linked website and server must both be active before this repository can be deployed.') }}
        </x-ui.alert>
    @endif

    @error('plan')
        <x-ui.alert tone="danger" class="my-4">
            {{ $message }}
            <a href="{{ route('billing.index') }}" class="font-bold underline">{{ __('View plans') }}</a>
        </x-ui.alert>
    @enderror

    @php
        $latestBuild = $builds->first();
        $latestBuildStatusTone = match ($latestBuild?->status) {
            \App\Models\Build::STATUS_SUCCEEDED => 'success',
            \App\Models\Build::STATUS_FAILED => 'danger',
            \App\Models\Build::STATUS_CANCELED, \App\Models\Build::STATUS_TIMING_OUT => 'warning',
            \App\Models\Build::STATUS_DEPLOYING, \App\Models\Build::STATUS_RUNNING => 'accent',
            default => 'neutral',
        };
        $repositorySetupNeedsAttention = $latestBuild?->statusEnum()?->isActive() === true
            || $latestBuild?->status === \App\Models\Build::STATUS_FAILED;
        $deploymentInsightsNeedAttention = $repositorySetupNeedsAttention;
    @endphp

    @if ($latestBuild)
        <section id="repository-latest-deployment" class="ui-card my-6 p-5" aria-labelledby="repository-latest-deployment-title">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Overview') }}</p>
                    <h2 id="repository-latest-deployment-title" class="mt-1 text-xl font-black text-primary">{{ __('Latest deployment · Build #:id', ['id' => $latestBuild->id]) }}</h2>
                    <p class="mt-1 text-sm text-secondary">
                        {{ __('Triggered :time by :source', ['time' => $latestBuild->created_at->diffForHumans(), 'source' => ucfirst($latestBuild->trigger_source)]) }}
                        @if ($latestBuild->durationLabel())
                            · {{ __(':duration', ['duration' => $latestBuild->durationLabel()]) }}
                        @endif
                    </p>
                </div>
                <x-ui.badge :tone="$latestBuildStatusTone">{{ str($latestBuild->status)->replace('_', ' ')->headline() }}</x-ui.badge>
            </div>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Revision') }}</dt>
                    <dd class="mt-1 break-all font-mono text-sm text-primary">
                        @if ($latestBuild->revision)
                            @if ($revisionUrl = $repository->revisionUrl($latestBuild->revision))
                                <a href="{{ $revisionUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ $latestBuild->shortRevision() }}</a>
                            @else
                                {{ $latestBuild->shortRevision() }}
                            @endif
                        @else
                            {{ __('Repository branch') }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-secondary">{{ __('Deployment result') }}</dt>
                    <dd class="mt-1 text-sm text-primary">
                        @if ($latestBuild->failure_message)
                            {{ $latestBuild->failure_message }}
                        @else
                            {{ __('Open the deployment for timeline, approval and recovery context.') }}
                        @endif
                    </dd>
                </div>
            </dl>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-ui.button :href="route('builds.show', $latestBuild)" variant="primary">{{ __('View latest deployment') }}</x-ui.button>
                <x-ui.button :href="route('builds.index', ['repository_id' => $repository->id])" variant="secondary">{{ __('View all deployments') }}</x-ui.button>
            </div>
        </section>
    @endif

    @if ($isFirstDeployment)
        <section class="ui-card my-6 p-5" aria-labelledby="first-deployment-title">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('First deployment') }}</p>
                    <h2 id="first-deployment-title" class="mt-1 text-xl font-black text-primary">{{ __('Review the launch checks') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm text-secondary">{{ __('Required checks must pass before launch. Recommended checks improve verification, recovery, and automatic delivery but can be completed later.') }}</p>
                </div>
                @if ($deploymentPreflight['level'] === 'ready')
                    <x-ui.badge tone="success">{{ str($deploymentPreflight['level'])->headline() }} · {{ $deploymentPreflight['score'] }}/100</x-ui.badge>
                @elseif ($deploymentPreflight['level'] === 'blocked')
                    <x-ui.badge tone="danger">{{ str($deploymentPreflight['level'])->headline() }} · {{ $deploymentPreflight['score'] }}/100</x-ui.badge>
                @else
                    <x-ui.badge tone="warning">{{ str($deploymentPreflight['level'])->headline() }} · {{ $deploymentPreflight['score'] }}/100</x-ui.badge>
                @endif
            </div>

            <ul class="mt-5 grid gap-3 md:grid-cols-2">
                @foreach ($deploymentPreflight['checks'] as $check)
                    <li class="flex gap-3 rounded-xl border border-primary bg-secondary p-4">
                        <span aria-hidden="true" @class([
                            'font-black',
                            'text-green-600' => $check['status'] === 'passed',
                            'text-amber-600' => $check['status'] === 'warning',
                            'text-red-600' => $check['status'] === 'failed',
                        ])>{{ $check['status'] === 'passed' ? '✓' : '!' }}</span>
                        <span><strong class="block text-primary">{{ $check['name'] }}</strong><span class="mt-1 block text-xs text-secondary">{{ $check['detail'] }}</span></span>
                    </li>
                @endforeach
            </ul>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('repositories.deploy', $repository) }}">
                    @csrf
                    <x-ui.button type="submit" variant="primary" :disabled="$deploymentInProgress || ! $deploymentReady || $deploymentPlanBlocked">{{ __('Launch first deployment') }}</x-ui.button>
                </form>
                <x-ui.button :href="route('repositories.edit', $repository)" variant="secondary">{{ __('Review source settings') }}</x-ui.button>
                <x-ui.button :href="route('websites.edit', $repository->website)" variant="secondary">{{ __('Review website settings') }}</x-ui.button>
            </div>

            @if ($deploymentGuidance['steps'])
                <section class="mt-5 border-t border-primary pt-5" aria-labelledby="first-deployment-next-steps">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h3 id="first-deployment-next-steps" class="text-lg font-bold text-primary">{{ __('Next steps before launch') }}</h3>
                            <p class="mt-1 text-sm text-secondary">
                                {{ __(':completed of :total prerequisites confirmed. :blockers blocker(s) require attention; recommendations can be completed later.', ['completed' => $deploymentGuidance['completed'], 'total' => $deploymentGuidance['total'], 'blockers' => $deploymentGuidance['blockers']]) }}
                            </p>
                        </div>
                    </div>
                    <ul class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach ($deploymentGuidance['steps'] as $step)
                            <li class="rounded-xl border border-primary bg-secondary p-4">
                                <div class="flex gap-3">
                                    <span aria-hidden="true" @class([
                                        'font-black',
                                        'text-amber-600' => $step['status'] === 'warning',
                                        'text-red-600' => $step['status'] === 'failed',
                                    ])>{{ $step['status'] === 'failed' ? '!' : '○' }}</span>
                                    <div>
                                        <strong class="block text-primary">{{ $step['title'] }}</strong>
                                        <span class="mt-1 block text-xs text-secondary">{{ $step['detail'] }}</span>
                                        <a href="{{ $step['url'] }}" class="mt-2 inline-block text-xs font-bold text-ternary underline">{{ $step['action'] }}</a>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @else
                <p class="mt-5 border-t border-primary pt-5 text-sm font-semibold text-green-700">{{ __('All first-deployment checks are confirmed. You can launch this revision.') }}</p>
            @endif
        </section>
    @endif

    <section class="ui-card my-6 p-5" aria-labelledby="repository-layout-title">
        <h2 id="repository-layout-title" class="text-xl font-semibold text-primary">{{ __('Deployment layout') }}</h2>
        <p class="mt-1 text-sm text-secondary">
            {{ __('This target deploys from :root. The repository root is used when no service directory is configured.', ['root' => $repository->deploymentRoot() === '.' ? __('the repository root') : $repository->deploymentRoot()]) }}
        </p>
        @if ($repository->deploymentRoot() !== '.')
            <p class="mt-2 text-sm text-secondary">
                {{ __('Build and post-deployment hooks, runtime processes, PHP public files, application logs and restore maintenance commands are scoped to this service directory.') }}
            </p>
        @endif
    </section>

    @php
        $oneTimeWebhookSecret = session("repository:{$repository->id}:webhook_secret");
    @endphp
    <section id="deployment-webhook" class="ui-card my-6 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold text-primary">{{ __('Automatic push deployments') }}</h2>
                <p class="mt-1 text-sm text-secondary">
                    {{ __('Deploy the configured branch after an authenticated source-control push.') }}
                </p>
            </div>
            @if ($repository->webhook_enabled)
                <x-ui.badge tone="success">{{ __('Enabled') }}</x-ui.badge>
            @else
                <x-ui.badge>{{ __('Disabled') }}</x-ui.badge>
            @endif
        </div>

        <div class="mt-4">
            <label for="webhook-url" class="block text-xs font-semibold uppercase text-secondary">{{ __('Payload URL') }}</label>
            <input
                id="webhook-url"
                type="text"
                readonly
                value="{{ route('webhooks.repositories.receive', $repository) }}"
                class="input secondary mt-1 w-full rounded-lg font-mono text-sm"
            >
        </div>

        @if ($oneTimeWebhookSecret)
            <x-ui.alert tone="warning" class="mt-4">
                <p class="font-semibold">{{ __('Copy this webhook secret now. It will not be shown again.') }}</p>
                <input
                    type="text"
                    readonly
                    value="{{ $oneTimeWebhookSecret }}"
                    class="input secondary mt-2 w-full rounded-lg font-mono text-sm"
                >
            </x-ui.alert>
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
            @if ($repository->auto_deploy_include_paths || $repository->auto_deploy_exclude_paths)
                <p class="mt-2">
                    {{ __('Automatic path filtering is enabled. A delivery is skipped only when the provider reports changed paths and none affect this deployment target.') }}
                </p>
            @endif
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
                            class="input secondary rounded-lg"
                        >
                        <x-forms.errors name="signing_token" />
                    </div>
                @endif
                <button
                    type="submit"
                    class="button button--primary"
                    @if ($repository->webhook_enabled)
                        onclick="return confirm({{ Illuminate\Support\Js::from(__('Rotate the webhook secret for :repository? The current secret will stop working immediately.', ['repository' => $repository->name])) }})"
                    @endif
                >
                    {{ $repository->webhook_enabled ? __('Rotate webhook secret') : __('Enable webhook') }}
                </button>
            </form>

            @if ($repository->webhook_enabled)
                <form
                    method="POST"
                    action="{{ route('repositories.webhook.destroy', $repository) }}"
                    onsubmit="return confirm({{ Illuminate\Support\Js::from(__('Disable webhook deployments for :repository?', ['repository' => $repository->name])) }})"
                >
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">{{ __('Disable webhook') }}</x-ui.button>
                </form>
            @endif
        </div>

        @php
            $deliveryFiltersActive = array_filter($deliveryFilters, fn ($value) => $value !== null);
            $webhookDeliveryNeedsAttention = $deliveryFiltersActive !== []
                || $deliveryMetrics['queued'] > 0
                || $deliveryMetrics['pending'] > 0;
        @endphp
        <details
            id="webhook-delivery-history"
            class="group mt-8 overflow-hidden"
            @if ($webhookDeliveryNeedsAttention) open @endif
        >
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 border-t border-primary pt-6 font-bold text-primary [&::-webkit-details-marker]:hidden">
                <span>
                    <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Automation') }}</span>
                    <span class="mt-1 block text-lg">{{ __('Webhook delivery history') }}</span>
                    <span class="mt-1 block text-sm font-normal text-secondary">{{ trans_choice(':count matching delivery|:count matching deliveries', $deliveryMetrics['total'], ['count' => $deliveryMetrics['total']]) }}</span>
                </span>
                <span class="text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
            </summary>
            <div class="border-t border-primary pt-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-primary">{{ __('Webhook delivery history') }}</h3>
                    <p class="mt-1 text-sm text-secondary">{{ __('Review accepted deliveries without exposing webhook payloads or credentials.') }}</p>
                </div>
                <form method="GET" action="{{ route('repositories.show', $repository) }}#webhook-deliveries" class="flex flex-wrap items-end gap-2">
                    <div>
                        <label for="delivery_status" class="block text-xs font-semibold uppercase text-secondary">{{ __('Status') }}</label>
                        <select id="delivery_status" name="delivery_status" class="input secondary mt-1 rounded-lg">
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
                            class="input secondary mt-1 rounded-lg"
                        >
                    </div>
                    <div>
                        <label for="delivery_date_to" class="block text-xs font-semibold uppercase text-secondary">{{ __('Received through') }}</label>
                        <input
                            id="delivery_date_to"
                            name="delivery_date_to"
                            type="date"
                            value="{{ $deliveryFilters['delivery_date_to'] }}"
                            class="input secondary mt-1 rounded-lg"
                        >
                    </div>
                    <x-ui.button type="submit" variant="primary">{{ __('Apply') }}</x-ui.button>
                    @if (array_filter($deliveryFilters, fn ($value) => $value !== null))
                        <x-ui.button :href="route('repositories.show', $repository).'#webhook-deliveries'" variant="ghost">{{ __('Clear') }}</x-ui.button>
                    @endif
                    <x-ui.button :href="route('repositories.webhook-deliveries.export', [$repository, ...array_filter($deliveryFilters, fn ($value) => $value !== null)])" variant="secondary">{{ __('Export CSV') }}</x-ui.button>
                </form>
            </div>

            <dl class="ui-insight-grid mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-7">
                <x-ui.stat :label="__('Matching deliveries')" :value="$deliveryMetrics['total']" />
                <x-ui.stat :label="__('Queued deliveries')" :value="$deliveryMetrics['queued']" />
                <x-ui.stat :label="__('Pending deliveries')" :value="$deliveryMetrics['pending']" />
                <x-ui.stat :label="__('Skipped deliveries')" :value="$deliveryMetrics['skipped']" />
                <x-ui.stat :label="__('Unavailable deliveries')" :value="$deliveryMetrics['unavailable']" />
                <x-ui.stat :label="__('Superseded deliveries')" :value="$deliveryMetrics['superseded']" />
                <x-ui.stat :label="__('Received deliveries')" :value="$deliveryMetrics['received']" />
            </dl>

            <div id="webhook-deliveries" class="mt-4">
                @if ($webhookDeliveries->isEmpty())
                    <p class="rounded-lg border border-primary p-4 text-sm text-secondary">
                        {{ array_filter($deliveryFilters, fn ($value) => $value !== null) ? __('No webhook deliveries match these filters.') : __('No webhook deliveries have been accepted yet.') }}
                    </p>
                @else
                    <div class="ui-card divide-y divide-primary overflow-hidden" aria-label="{{ __('Webhook delivery history') }}">
                        @foreach ($webhookDeliveries as $delivery)
                            @php($deliveryTone = match ($delivery->status) {
                                \App\Models\RepositoryWebhookDelivery::STATUS_QUEUED => 'success',
                                \App\Models\RepositoryWebhookDelivery::STATUS_PENDING => 'warning',
                                \App\Models\RepositoryWebhookDelivery::STATUS_UNAVAILABLE => 'danger',
                                \App\Models\RepositoryWebhookDelivery::STATUS_SKIPPED => 'accent',
                                default => 'neutral',
                            })
                            <article data-webhook-delivery class="p-4 sm:p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-mono text-sm text-primary">{{ $delivery->delivery_id }}</p>
                                        @if ($delivery->commit_message)
                                            <p class="mt-1 max-w-2xl truncate text-sm text-secondary" title="{{ $delivery->commit_message }}">{{ $delivery->commit_message }}</p>
                                        @endif
                                    </div>
                                    <x-ui.badge :tone="$deliveryTone">{{ str($delivery->status)->replace('_', ' ') }}</x-ui.badge>
                                </div>
                                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-3">
                                    <div>
                                        <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Revision') }}</dt>
                                        <dd class="mt-1 font-mono text-xs text-primary">
                                            @if ($delivery->revision)
                                                @if ($revisionUrl = $repository->revisionUrl($delivery->revision))
                                                    <a href="{{ $revisionUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ str($delivery->revision)->take(12) }}</a>
                                                @else
                                                    {{ str($delivery->revision)->take(12) }}
                                                @endif
                                            @else
                                                &mdash;
                                            @endif
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Result') }}</dt>
                                        <dd class="mt-1 text-primary">
                                            @if ($delivery->build)
                                                <a href="{{ route('builds.show', $delivery->build) }}" class="font-medium hover:underline">{{ __('Build #:id', ['id' => $delivery->build->id]) }}</a>
                                            @elseif ($delivery->status === \App\Models\RepositoryWebhookDelivery::STATUS_SUPERSEDED)
                                                {{ __('Replaced by a newer push') }}
                                            @elseif ($delivery->status === \App\Models\RepositoryWebhookDelivery::STATUS_PENDING)
                                                {{ __('Waiting for active deployment') }}
                                            @elseif ($delivery->status === \App\Models\RepositoryWebhookDelivery::STATUS_UNAVAILABLE)
                                                {{ __('Deployment unavailable') }}
                                            @elseif ($delivery->status === \App\Models\RepositoryWebhookDelivery::STATUS_SKIPPED)
                                                {{ __('No configured deployment path changed') }}
                                            @else
                                                &mdash;
                                            @endif
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Received') }}</dt>
                                        <dd class="mt-1 text-primary" title="{{ $delivery->created_at }}">{{ $delivery->created_at->diffForHumans() }}</dd>
                                    </div>
                                </dl>
                            </article>
                        @endforeach
                    </div>
                    <div class="mt-4">{{ $webhookDeliveries->links() }}</div>
                @endif
            </div>
            </div>
        </details>
    </section>

    <!--
     ! ------------------------------------------------------------
     ! Repository information
     ! ------------------------------------------------------------
     !-->
    <x-ui.card class="mt-6 p-5">
        <div class="grid gap-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
        <div class="flex items-start gap-3 text-secondary">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-secondary" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#external-link"></use>
            </svg>
            <div>
            <span class="font-semibold text-primary">
                {{ __('URL') }}
            </span>
                <div class="mt-1 break-all font-mono text-xs">
                    {{ $repository->url }}
                </div>
            </div>
        </div>
        <div class="flex items-start gap-3 text-secondary">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-secondary" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#external-link"></use>
            </svg>
            <div>
                <span class="font-semibold text-primary">{{ __('Branch') }}</span>
                <span class="mt-1 block font-mono text-xs">{{ $repository->branch }}</span>
            </div>
        </div>
        @if ($repository->build_commands)
            <div class="flex items-start gap-3 text-secondary">
                <span class="mt-0.5 text-green-600" aria-hidden="true">✓</span>
                <span>{{ __('Build hook configured') }}</span>
            </div>
        @endif
        @if ($repository->post_deployment_commands)
            <div class="flex items-start gap-3 text-secondary">
                <span class="mt-0.5 text-green-600" aria-hidden="true">✓</span>
                <span>{{ __('Post-deployment hook configured') }}</span>
            </div>
        @endif
        </div>
    </x-ui.card>

    <details
        id="repository-setup"
        class="ui-responsive-details group ui-card mt-6 overflow-hidden"
        @if ($repositorySetupNeedsAttention) open @endif
        data-responsive-details
        data-responsive-details-mobile-expanded="{{ $repositorySetupNeedsAttention ? 'true' : 'false' }}"
    >
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-primary [&::-webkit-details-marker]:hidden">
            <span>
                <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Deployment timeline') }}</span>
                <span class="mt-1 block text-lg">{{ __('Deployment timeline') }}</span>
                <span class="mt-1 block text-sm font-normal text-secondary">
                    {{ $latestBuild ? __('Latest build: :status', ['status' => str($latestBuild->status)->replace('_', ' ')->headline()]) : __('No deployment has started yet.') }}
                </span>
            </span>
            <span class="text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <div class="ui-responsive-details__content border-t border-primary p-5">
            <livewire:repository-deployment-timeline :model="$repository"></livewire:repository-deployment-timeline>
        </div>
    </details>

    <details
        id="repository-deployment-insights"
        class="ui-responsive-details group mt-10 ui-card overflow-hidden"
        @if ($deploymentInsightsNeedAttention) open @endif
        data-responsive-details
        data-responsive-details-mobile-expanded="{{ $deploymentInsightsNeedAttention ? 'true' : 'false' }}"
    >
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-primary [&::-webkit-details-marker]:hidden">
            <span>
                <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Insights') }}</span>
                <span class="mt-1 block text-lg">{{ __('Deployment insights') }}</span>
                <span class="mt-1 block text-sm font-normal text-secondary">
                    {{ trans_choice(':count recorded deployment|:count recorded deployments', $deploymentMetrics['total'], ['count' => $deploymentMetrics['total']]) }}
                    @if ($deploymentMetrics['success_rate'] !== null)
                        · {{ __(':rate% completed success', ['rate' => $deploymentMetrics['success_rate']]) }}
                    @endif
                </span>
            </span>
            <span class="text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <section class="ui-responsive-details__content border-t border-primary p-5" aria-labelledby="deployment-insights-heading">
            <div>
                <h2 id="deployment-insights-heading" class="text-2xl font-bold text-primary">{{ __('Deployment insights') }}</h2>
                <p class="mt-1 text-sm text-secondary">
                    {{ __('Outcome totals cover all recorded deployments. Median duration uses up to the 20 most recent deployments with valid start and finish times.') }}
                </p>
            </div>
            <dl class="ui-insight-grid mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <a href="{{ route('builds.index', ['repository_id' => $repository->id]) }}" class="ui-card ui-card--interactive p-4">
                <dt class="ui-stat__label">{{ __('Total deployments') }}</dt>
                <dd class="ui-stat__value">{{ $deploymentMetrics['total'] }}</dd>
            </a>
            <a href="{{ route('builds.index', ['repository_id' => $repository->id, 'status' => \App\Models\Build::STATUS_SUCCEEDED]) }}" class="ui-card ui-card--interactive p-4">
                <dt class="ui-stat__label">{{ __('Succeeded') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-green-600">{{ $deploymentMetrics['succeeded'] }}</dd>
            </a>
            <a href="{{ route('builds.index', ['repository_id' => $repository->id, 'status' => \App\Models\Build::STATUS_FAILED]) }}" class="ui-card ui-card--interactive p-4">
                <dt class="ui-stat__label">{{ __('Failed') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-red-600">{{ $deploymentMetrics['failed'] }}</dd>
            </a>
            <div class="ui-card p-4">
                <dt class="ui-stat__label">{{ __('Completed-run success rate') }}</dt>
                <dd class="ui-stat__value">
                    {{ $deploymentMetrics['success_rate'] !== null ? $deploymentMetrics['success_rate'].'%' : __('Not available') }}
                </dd>
                <p class="mt-1 text-xs text-secondary">{{ __('Canceled and active runs are excluded.') }}</p>
            </div>
            <div class="ui-card p-4">
                <dt class="ui-stat__label">{{ __('Recent median duration') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-primary">
                    {{ $deploymentMetrics['median_duration_seconds'] !== null ? \App\Models\Build::formatDuration($deploymentMetrics['median_duration_seconds']) : __('Not recorded') }}
                </dd>
                <p class="mt-1 text-xs text-secondary">
                    {{ trans_choice(':count timed deployment|:count timed deployments', $deploymentMetrics['duration_sample_size'], ['count' => $deploymentMetrics['duration_sample_size']]) }}
                </p>
            </div>
            </dl>
        </section>
    </details>

    <details
        id="repository-deployment-history"
        class="group mt-10 overflow-hidden"
        @if ($latestBuild?->statusEnum()?->isActive() === true) open @endif
    >
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-bold text-primary [&::-webkit-details-marker]:hidden">
            <span>
                <span class="block text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Deployments') }}</span>
                <span class="mt-1 block text-2xl">{{ __('Recent deployment history') }}</span>
                <span class="mt-1 block text-sm font-normal text-secondary">{{ trans_choice(':count recent deployment|:count recent deployments', $builds->count(), ['count' => $builds->count()]) }}</span>
            </span>
            <span class="text-xl font-normal text-secondary transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <div class="mt-4 border-t border-primary pt-4">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 id="deployment-history-title" class="sr-only">{{ __('Deployment history') }}</h2>
                <x-ui.button :href="route('builds.index', ['repository_id' => $repository->id])" variant="secondary" class="ml-auto">
                    {{ __('View all deployments') }}
                </x-ui.button>
            </div>
            @forelse ($builds as $build)
                <div class="ui-card mb-3 flex items-center justify-between gap-4 p-4">
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
                    @if ($build->status === \App\Models\Build::STATUS_SUCCEEDED)
                        <x-ui.badge tone="success">{{ str($build->status)->replace('_', ' ') }}</x-ui.badge>
                    @elseif ($build->status === \App\Models\Build::STATUS_FAILED)
                        <x-ui.badge tone="danger">{{ str($build->status)->replace('_', ' ') }}</x-ui.badge>
                    @elseif (in_array($build->status, [\App\Models\Build::STATUS_CANCELED, \App\Models\Build::STATUS_TIMING_OUT], true))
                        <x-ui.badge tone="warning">{{ str($build->status)->replace('_', ' ') }}</x-ui.badge>
                    @elseif (in_array($build->status, [\App\Models\Build::STATUS_DEPLOYING, \App\Models\Build::STATUS_RUNNING], true))
                        <x-ui.badge tone="accent">{{ str($build->status)->replace('_', ' ') }}</x-ui.badge>
                    @else
                        <x-ui.badge>{{ str($build->status)->replace('_', ' ') }}</x-ui.badge>
                    @endif
                </div>
            @empty
                <x-ui.empty-state
                    :title="__('No deployments yet')"
                    :description="__('Deploy this repository to create its first build.')"
                />
            @endforelse
        </div>
    </details>

</x-layouts.app>
