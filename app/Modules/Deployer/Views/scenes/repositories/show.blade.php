<x-layouts.app>

    @php
        $repositoryEditOpen = $editDialogOpen;
        $repositoryEditUrl = route('repositories.show', ['repository' => $repository, 'dialog' => 'edit-repository']);
        $repositoryPageUrl = route('repositories.show', [
            'repository' => $repository,
            ...array_filter($deliveryFilters, fn ($value) => $value !== null),
        ]);
        $repositoryEditContentUrl = route('repositories.edit', ['repository' => $repository, 'dialog' => 'edit-repository', 'fragment' => 1, 'return_to' => $repositoryPageUrl]);
        $websiteEditOpen = $websiteEditDialogOpen;
        $websiteEditUrl = (string) \Illuminate\Support\Uri::of($repositoryPageUrl)->withQuery(['dialog' => 'edit-website']);
        $websiteEditContentUrl = route('websites.edit', ['website' => $repository->website, 'dialog' => 'edit-website', 'fragment' => 1, 'return_to' => $repositoryPageUrl]);
        $webhookDialogId = 'repository-webhook-settings-dialog';
        $webhookDialogOpen = request()->query('dialog') === 'repository-webhook-settings';
        $webhookDialogUrl = (string) \Illuminate\Support\Uri::of($repositoryPageUrl)->withQuery(['dialog' => 'repository-webhook-settings']);
        $canUpdateRepository = auth()->user()?->can('update', $repository) ?? false;
        $deliveryDialogId = 'repository-webhook-delivery-dialog';
        $deliveryDialogOpen = $selectedWebhookDelivery !== null;
        $deliveryDialogQuery = array_filter([
            ...$deliveryFilters,
            'webhook_page' => $webhookDeliveries->currentPage() > 1 ? $webhookDeliveries->currentPage() : null,
        ], fn ($value) => $value !== null);
        $deliveryDialogHistoryUrl = $selectedWebhookDelivery
            ? route('repositories.show', [
                'repository' => $repository,
                ...$deliveryDialogQuery,
                'dialog' => "webhook-delivery-{$selectedWebhookDelivery->id}",
            ])
            : null;
        $deliveryDialogContentUrl = $selectedWebhookDelivery
            ? route('repositories.show', [
                'repository' => $repository,
                'fragment' => 'webhook-delivery',
                'delivery_id' => $selectedWebhookDelivery->id,
            ])
            : null;
    @endphp

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
    <x-signal.ui.page-header
        :title="$repository->name"
        :description="$repository->description"
    >
        <x-slot:actions>

            <form method="POST" action="{{ route('repositories.deploy', $repository) }}">
                @csrf
                <x-signal.ui.button type="submit" variant="primary" :disabled="$deploymentInProgress || ! $deploymentReady || $deploymentPlanBlocked">
                    <svg class="h-4 w-4" aria-hidden="true">
                        <use xlink:href="/assets/images/icons.svg#cloud-upload"></use>
                    </svg>
                    {{ ! $deploymentReady || $deploymentPlanBlocked ? __('Deployment unavailable') : ($deploymentInProgress ? __('Deployment in progress') : __('Deploy')) }}
                </x-signal.ui.button>
            </form>

            <x-signal.ui.button
                :href="$repositoryEditUrl"
                data-modal-trigger="repository-edit-dialog"
                data-modal-content-url="{{ $repositoryEditContentUrl }}"
                aria-controls="repository-edit-dialog"
                aria-expanded="{{ $repositoryEditOpen ? 'true' : 'false' }}"
                variant="secondary"
            >
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#pencil-alt"></use>
                </svg>
                {{ __('Edit') }}
            </x-signal.ui.button>

            <x-signal.overlays.delete-confirmation
                id="delete-repository"
                :route="route('repositories.destroy', $repository)"
                :title="__('Delete')"
                :description="__('Are you sure you want to delete this repository?')"
            ></x-signal.overlays.delete-confirmation>

            <x-signal.ui.button type="button" variant="danger" data-modal-trigger="delete-repository" aria-controls="delete-repository" aria-expanded="false" class="ui-btn-sm">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use xlink:href="/assets/images/icons.svg#trash"></use>
                </svg>
                {{ __('Delete') }}
            </x-signal.ui.button>

        </x-slot:actions>
    </x-signal.ui.page-header>

    <x-signal.ui.local-nav class="mt-6" :label="__('Repository sections')">
        <a href="#repository-overview" class="ui-local-nav__link">{{ __('Overview') }}</a>
        <a href="#deployment-webhook" class="ui-local-nav__link">{{ __('Automation') }}</a>
        <a href="#repository-setup" class="ui-local-nav__link">{{ __('Timeline') }}</a>
        <a href="#repository-deployment-insights" class="ui-local-nav__link">{{ __('Insights') }}</a>
        <a href="#repository-deployment-history" class="ui-local-nav__link">{{ __('History') }}</a>
    </x-signal.ui.local-nav>

    @if (! $deploymentReady)
        <x-signal.ui.panel as="aside" class="ui-panel my-4 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-warning)" role="status">
            {{ __('The linked website and server must both be active before this repository can be deployed.') }}
        </x-signal.ui.panel>
    @endif

    @error('plan')
        <x-signal.ui.panel as="aside" class="ui-panel my-4 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-danger)" role="alert">
            {{ $message }}
            <a href="{{ route('billing.index') }}" class="ui-link font-bold">{{ __('View plans') }}</a>
        </x-signal.ui.panel>
    @enderror

    @php
        $latestBuild = $builds->first();
        $latestBuildStatusTone = match ($latestBuild?->status) {
            \App\Modules\Deployer\Models\Build::STATUS_SUCCEEDED => 'success',
            \App\Modules\Deployer\Models\Build::STATUS_FAILED => 'danger',
            \App\Modules\Deployer\Models\Build::STATUS_CANCELED, \App\Modules\Deployer\Models\Build::STATUS_TIMING_OUT => 'warning',
            \App\Modules\Deployer\Models\Build::STATUS_DEPLOYING, \App\Modules\Deployer\Models\Build::STATUS_RUNNING => 'accent',
            default => 'neutral',
        };
        $repositorySetupNeedsAttention = $latestBuild?->statusEnum()?->isActive() === true
            || $latestBuild?->status === \App\Modules\Deployer\Models\Build::STATUS_FAILED;
        $deploymentInsightsNeedAttention = $repositorySetupNeedsAttention;
    @endphp

    @if ($latestBuild)
        <x-signal.ui.panel as="section" id="repository-latest-deployment" class="ui-panel my-6 p-5 sm:p-6" aria-labelledby="repository-latest-deployment-title">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="ui-eyebrow">{{ __('Overview') }}</p>
                    <h2 id="repository-latest-deployment-title" class="mt-2 text-xl font-extrabold text-ink">{{ __('Latest deployment · Build #:id', ['id' => $latestBuild->id]) }}</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ __('Triggered :time by :source', ['time' => $latestBuild->created_at->diffForHumans(), 'source' => ucfirst($latestBuild->trigger_source)]) }}
                        @if ($latestBuild->durationLabel())
                            · {{ __(':duration', ['duration' => $latestBuild->durationLabel()]) }}
                        @endif
                    </p>
                </div>
                <x-signal.ui.badge :tone="$latestBuildStatusTone">{{ str($latestBuild->status)->replace('_', ' ')->headline() }}</x-signal.ui.badge>
            </div>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="ui-eyebrow text-[0.65rem]">{{ __('Revision') }}</dt>
                    <dd class="mt-1 break-all font-mono text-sm text-ink">
                        @if ($latestBuild->revision)
                            @if ($revisionUrl = $repository->revisionUrl($latestBuild->revision))
                                <a href="{{ $revisionUrl }}" target="_blank" rel="noopener noreferrer" class="ui-link">{{ $latestBuild->shortRevision() }}</a>
                            @else
                                {{ $latestBuild->shortRevision() }}
                            @endif
                        @else
                            {{ __('Repository branch') }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="ui-eyebrow text-[0.65rem]">{{ __('Deployment result') }}</dt>
                    <dd class="mt-1 text-sm text-ink">
                        @if ($latestBuild->failure_message)
                            {{ $latestBuild->failure_message }}
                        @else
                            {{ __('Open the deployment for timeline, approval and recovery context.') }}
                        @endif
                    </dd>
                </div>
            </dl>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-signal.ui.button :href="route('builds.show', $latestBuild)" variant="primary">{{ __('View latest deployment') }}</x-signal.ui.button>
                <x-signal.ui.button :href="route('builds.index', ['repository_id' => $repository->id])" variant="secondary" class="ui-btn-sm">{{ __('View all deployments') }}</x-signal.ui.button>
            </div>
        </x-signal.ui.panel>
    @endif

    @if ($isFirstDeployment)
        <x-signal.ui.panel as="section" class="ui-panel my-6 p-5 sm:p-6" aria-labelledby="first-deployment-title">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="ui-eyebrow">{{ __('First deployment') }}</p>
                    <h2 id="first-deployment-title" class="mt-2 text-xl font-extrabold text-ink">{{ __('Review the launch checks') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm text-muted">{{ __('Required checks must pass before launch. Recommended checks improve verification, recovery, and automatic delivery but can be completed later.') }}</p>
                </div>
                @if ($deploymentPreflight['level'] === 'ready')
                    <x-signal.ui.badge tone="success">{{ str($deploymentPreflight['level'])->headline() }} · {{ $deploymentPreflight['score'] }}/100</x-signal.ui.badge>
                @elseif ($deploymentPreflight['level'] === 'blocked')
                    <x-signal.ui.badge tone="danger">{{ str($deploymentPreflight['level'])->headline() }} · {{ $deploymentPreflight['score'] }}/100</x-signal.ui.badge>
                @else
                    <x-signal.ui.badge tone="warning">{{ str($deploymentPreflight['level'])->headline() }} · {{ $deploymentPreflight['score'] }}/100</x-signal.ui.badge>
                @endif
            </div>

            <ul class="mt-5 grid gap-3 md:grid-cols-2">
                @foreach ($deploymentPreflight['checks'] as $check)
                    <li class="flex gap-3 rounded-card border border-line bg-surface-muted p-4">
                        <span
                            aria-hidden="true"
                            class="font-extrabold"
                            style="color: {{ match ($check['status']) {
                                'passed' => 'var(--ui-success)',
                                'warning' => 'var(--ui-warning)',
                                default => 'var(--ui-danger)',
                            } }}"
                        >{{ $check['status'] === 'passed' ? '✓' : '!' }}</span>
                        <span><strong class="block text-ink">{{ $check['name'] }}</strong><span class="mt-1 block text-xs text-muted">{{ $check['detail'] }}</span></span>
                    </li>
                @endforeach
            </ul>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('repositories.deploy', $repository) }}">
                    @csrf
                    <x-signal.ui.button type="submit" variant="primary" class="ui-btn-sm" :disabled="$deploymentInProgress || ! $deploymentReady || $deploymentPlanBlocked">{{ __('Launch first deployment') }}</x-signal.ui.button>
                </form>
                <x-signal.ui.button
                    :href="$repositoryEditUrl"
                    data-modal-trigger="repository-edit-dialog"
                    data-modal-content-url="{{ $repositoryEditContentUrl }}"
                    aria-controls="repository-edit-dialog"
                    aria-expanded="{{ $repositoryEditOpen ? 'true' : 'false' }}"
                    variant="secondary"
                >{{ __('Review source settings') }}</x-signal.ui.button>
                <x-signal.ui.button :href="$websiteEditUrl" data-modal-trigger="website-edit-dialog" data-modal-content-url="{{ $websiteEditContentUrl }}" aria-controls="website-edit-dialog" aria-expanded="{{ $websiteEditOpen ? 'true' : 'false' }}" variant="secondary">{{ __('Review website settings') }}</x-signal.ui.button>
            </div>

            @if ($deploymentGuidance['steps'])
                <section class="mt-5 border-t border-line pt-5" aria-labelledby="first-deployment-next-steps">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h3 id="first-deployment-next-steps" class="text-lg font-extrabold text-ink">{{ __('Next steps before launch') }}</h3>
                            <p class="mt-1 text-sm text-muted">
                                {{ __(':completed of :total prerequisites confirmed. :blockers blocker(s) require attention; recommendations can be completed later.', ['completed' => $deploymentGuidance['completed'], 'total' => $deploymentGuidance['total'], 'blockers' => $deploymentGuidance['blockers']]) }}
                            </p>
                        </div>
                    </div>
                    <ul class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach ($deploymentGuidance['steps'] as $step)
                            <li class="rounded-card border border-line bg-surface-muted p-4">
                                <div class="flex gap-3">
                                    <span
                                        aria-hidden="true"
                                        class="font-extrabold"
                                        style="color: {{ $step['status'] === 'failed' ? 'var(--ui-danger)' : 'var(--ui-warning)' }}"
                                    >{{ $step['status'] === 'failed' ? '!' : '○' }}</span>
                                    <div>
                                        <strong class="block text-ink">{{ $step['title'] }}</strong>
                                        <span class="mt-1 block text-xs text-muted">{{ $step['detail'] }}</span>
                                        <a href="{{ $step['url'] }}" class="ui-link mt-2 inline-block text-xs font-bold">{{ $step['action'] }}</a>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @else
                <p class="mt-5 border-t border-line pt-5 text-sm font-semibold" style="color: var(--ui-success)">{{ __('All first-deployment checks are confirmed. You can launch this revision.') }}</p>
            @endif
        </x-signal.ui.panel>
    @endif

    <x-signal.ui.panel as="section" id="repository-overview" class="ui-panel my-6 scroll-mt-24 p-5 sm:p-6" data-repository-overview aria-labelledby="repository-layout-title">
        <p class="ui-eyebrow">{{ __('Source target') }}</p>
        <h2 id="repository-layout-title" class="mt-2 text-xl font-extrabold text-ink">{{ __('Deployment layout') }}</h2>
        <p class="mt-1 text-sm text-muted">
            {{ __('This target deploys from :root. The repository root is used when no service directory is configured.', ['root' => $repository->deploymentRoot() === '.' ? __('the repository root') : $repository->deploymentRoot()]) }}
        </p>
        @if ($repository->deploymentRoot() !== '.')
            <p class="mt-2 text-sm text-muted">
                {{ __('Build and post-deployment hooks, runtime processes, PHP public files, application logs and restore maintenance commands are scoped to this service directory.') }}
            </p>
        @endif
    </x-signal.ui.panel>

    @php
        $oneTimeWebhookSecret = session("repository:{$repository->id}:webhook_secret");
    @endphp
    <x-signal.ui.panel as="section" id="deployment-webhook" class="ui-panel my-6 scroll-mt-24 p-5 sm:p-6" data-repository-automation>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="ui-eyebrow">{{ __('Automation') }}</p>
                <h2 class="mt-2 text-xl font-extrabold text-ink">{{ __('Automatic push deployments') }}</h2>
                <p class="mt-1 text-sm text-muted">
                    {{ __('Deploy the configured branch after an authenticated source-control push.') }}
                </p>
            </div>
            @if ($repository->webhook_enabled)
                <x-signal.ui.badge tone="success">{{ __('Enabled') }}</x-signal.ui.badge>
            @else
                <x-signal.ui.badge>{{ __('Disabled') }}</x-signal.ui.badge>
            @endif
        </div>

        <div class="mt-4">
            <label for="webhook-url" class="ui-label">{{ __('Payload URL') }}</label>
            <x-signal.ui.input
                id="webhook-url"
                type="text"
                readonly
                value="{{ route('webhooks.repositories.receive', $repository) }}"
                class="ui-input mt-2 w-full font-mono text-sm" :restore="false" />
        </div>

        @if ($oneTimeWebhookSecret)
            <x-signal.ui.panel as="aside" class="ui-panel mt-4 border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-primary)" role="alert">
                <p class="font-semibold">{{ __('Copy this webhook secret now. It will not be shown again.') }}</p>
                <x-signal.ui.input
                    type="text"
                    readonly
                    value="{{ $oneTimeWebhookSecret }}"
                    class="ui-input mt-2 w-full font-mono text-sm" :restore="false" />
            </x-signal.ui.panel>
        @endif

        <div class="mt-4 text-sm text-muted">
            @if ($repository->provider->provider === \App\Modules\Deployer\Models\Provider::TYPE_GITHUB)
                <p>{{ __('Create a GitHub push webhook using JSON content, the payload URL above, and the generated secret.') }}</p>
            @elseif ($repository->provider->provider === \App\Modules\Deployer\Models\Provider::TYPE_BITBUCKET)
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
                <p class="mt-2 font-medium" style="color: var(--ui-warning)">{{ __('A newer push is waiting for the active deployment to finish.') }}</p>
            @elseif ($repository->webhook_last_received_at)
                <p class="mt-2">{{ __('Last accepted delivery: :time', ['time' => $repository->webhook_last_received_at->diffForHumans()]) }}</p>
            @endif
        </div>

        @if ($canUpdateRepository)
            <div class="mt-5">
                <x-signal.ui.button
                    :href="$webhookDialogUrl"
                    data-modal-trigger="{{ $webhookDialogId }}"
                    aria-controls="{{ $webhookDialogId }}"
                    aria-expanded="{{ $webhookDialogOpen ? 'true' : 'false' }}"
                    variant="secondary"
                    class="ui-btn-sm"
                >
                    {{ $repository->webhook_enabled ? __('Manage webhook') : __('Enable webhook') }}
                </x-signal.ui.button>
            </div>
            <x-scenes.repositories.webhook-settings-dialog
                :repository="$repository"
                :open="$webhookDialogOpen"
            />
        @endif

        @php
            $deliveryFiltersActive = array_filter($deliveryFilters, fn ($value) => $value !== null);
            $webhookDeliveryNeedsAttention = $deliveryFiltersActive !== []
                || $deliveryMetrics['queued'] > 0
                || $deliveryMetrics['pending'] > 0;
        @endphp
        <details
            id="webhook-delivery-history"
            class="group mt-8 scroll-mt-24 overflow-hidden"
            @if ($webhookDeliveryNeedsAttention) open @endif
        >
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 border-t border-line pt-6 font-bold text-ink [&::-webkit-details-marker]:hidden">
                <span>
                    <span class="ui-eyebrow block">{{ __('Automation') }}</span>
                    <span class="mt-1 block text-lg">{{ __('Webhook delivery history') }}</span>
                    <span class="mt-1 block text-sm font-normal text-muted">{{ trans_choice(':count matching delivery|:count matching deliveries', $deliveryMetrics['total'], ['count' => $deliveryMetrics['total']]) }}</span>
                </span>
                <span class="text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
            </summary>
            <div class="border-t border-line pt-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h3 class="text-lg font-extrabold text-ink">{{ __('Webhook delivery history') }}</h3>
                    <p class="mt-1 text-sm text-muted">{{ __('Review accepted deliveries without exposing webhook payloads or credentials.') }}</p>
                </div>
                <form method="GET" action="{{ route('repositories.show', $repository) }}#webhook-deliveries" class="flex flex-wrap items-end gap-2">
                    <div>
                        <label for="delivery_status" class="ui-label">{{ __('Status') }}</label>
                        <x-signal.ui.select id="delivery_status" name="delivery_status" class="ui-input mt-2">
                            <option value="">{{ __('All statuses') }}</option>
                            @foreach ($deliveryStatuses as $status)
                                <option value="{{ $status }}" @selected($deliveryFilters['delivery_status'] === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                            @endforeach
                        </x-signal.ui.select>
                    </div>
                    <div>
                        <label for="delivery_date_from" class="ui-label">{{ __('Received from') }}</label>
                        <x-signal.ui.input
                            id="delivery_date_from"
                            name="delivery_date_from"
                            type="date"
                            value="{{ $deliveryFilters['delivery_date_from'] }}"
                            class="ui-input mt-2" :restore="false" />
                    </div>
                    <div>
                        <label for="delivery_date_to" class="ui-label">{{ __('Received through') }}</label>
                        <x-signal.ui.input
                            id="delivery_date_to"
                            name="delivery_date_to"
                            type="date"
                            value="{{ $deliveryFilters['delivery_date_to'] }}"
                            class="ui-input mt-2" :restore="false" />
                    </div>
                    <x-signal.ui.button type="submit" variant="primary" class="ui-btn-sm">{{ __('Apply') }}</x-signal.ui.button>
                    @if (array_filter($deliveryFilters, fn ($value) => $value !== null))
                        <x-signal.ui.button :href="route('repositories.show', $repository).'#webhook-deliveries'" variant="ghost" class="ui-btn-sm">{{ __('Clear') }}</x-signal.ui.button>
                    @endif
                    <x-signal.ui.button :href="route('repositories.webhook-deliveries.export', [$repository, ...array_filter($deliveryFilters, fn ($value) => $value !== null)])" variant="secondary" class="ui-btn-sm">{{ __('Export CSV') }}</x-signal.ui.button>
                </form>
            </div>

            <dl class="ui-insight-grid mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-7">
                <x-signal.ui.stat :label="__('Matching deliveries')" :value="$deliveryMetrics['total']" />
                <x-signal.ui.stat :label="__('Queued deliveries')" :value="$deliveryMetrics['queued']" />
                <x-signal.ui.stat :label="__('Pending deliveries')" :value="$deliveryMetrics['pending']" />
                <x-signal.ui.stat :label="__('Skipped deliveries')" :value="$deliveryMetrics['skipped']" />
                <x-signal.ui.stat :label="__('Unavailable deliveries')" :value="$deliveryMetrics['unavailable']" />
                <x-signal.ui.stat :label="__('Superseded deliveries')" :value="$deliveryMetrics['superseded']" />
                <x-signal.ui.stat :label="__('Received deliveries')" :value="$deliveryMetrics['received']" />
            </dl>

            <div id="webhook-deliveries" class="mt-4">
                @if ($webhookDeliveries->isEmpty())
                    <p class="rounded-card border border-line border-l-4 p-4 text-sm text-muted">
                        {{ array_filter($deliveryFilters, fn ($value) => $value !== null) ? __('No webhook deliveries match these filters.') : __('No webhook deliveries have been accepted yet.') }}
                    </p>
                @else
                    <x-signal.ui.panel class="ui-panel divide-y divide-line overflow-hidden" aria-label="{{ __('Webhook delivery history') }}">
                        @foreach ($webhookDeliveries as $delivery)
                            @php($deliveryTone = match ($delivery->status) {
                                \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_QUEUED => 'success',
                                \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_PENDING => 'warning',
                                \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_UNAVAILABLE => 'danger',
                                \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_SKIPPED => 'accent',
                                default => 'neutral',
                            })
                            <article data-webhook-delivery class="p-4 sm:p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-mono text-sm text-ink">{{ $delivery->delivery_id }}</p>
                                        @if ($delivery->commit_message)
                                            <p class="mt-1 max-w-2xl truncate text-sm text-muted" title="{{ $delivery->commit_message }}">{{ $delivery->commit_message }}</p>
                                        @endif
                                    </div>
                                    <x-signal.ui.badge :tone="$deliveryTone">{{ str($delivery->status)->replace('_', ' ') }}</x-signal.ui.badge>
                                </div>
                                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-3">
                                    <div>
                                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('Revision') }}</dt>
                                        <dd class="mt-1 font-mono text-xs text-ink">
                                            @if ($delivery->revision)
                                                @if ($revisionUrl = $repository->revisionUrl($delivery->revision))
                                                    <a href="{{ $revisionUrl }}" target="_blank" rel="noopener noreferrer" class="ui-link">{{ str($delivery->revision)->take(12) }}</a>
                                                @else
                                                    {{ str($delivery->revision)->take(12) }}
                                                @endif
                                            @else
                                                &mdash;
                                            @endif
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('Result') }}</dt>
                                        <dd class="mt-1 text-ink">
                                            @if ($delivery->build)
                                                <a href="{{ route('builds.show', $delivery->build) }}" class="ui-link font-medium">{{ __('Build #:id', ['id' => $delivery->build->id]) }}</a>
                                            @elseif ($delivery->status === \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_SUPERSEDED)
                                                {{ __('Replaced by a newer push') }}
                                            @elseif ($delivery->status === \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_PENDING)
                                                {{ __('Waiting for active deployment') }}
                                            @elseif ($delivery->status === \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_UNAVAILABLE)
                                                {{ __('Deployment unavailable') }}
                                            @elseif ($delivery->status === \App\Modules\Deployer\Models\RepositoryWebhookDelivery::STATUS_SKIPPED)
                                                {{ __('No configured deployment path changed') }}
                                            @else
                                                &mdash;
                                            @endif
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="ui-eyebrow text-[0.65rem]">{{ __('Received') }}</dt>
                                        <dd class="mt-1 text-ink" title="{{ $delivery->created_at }}">{{ $delivery->created_at->diffForHumans() }}</dd>
                                    </div>
                                </dl>
                                @php($deliveryDialogUrl = route('repositories.show', [
                                    'repository' => $repository,
                                    ...$deliveryDialogQuery,
                                    'dialog' => "webhook-delivery-{$delivery->id}",
                                ]))
                                @php($deliveryContentUrl = route('repositories.show', [
                                    'repository' => $repository,
                                    'fragment' => 'webhook-delivery',
                                    'delivery_id' => $delivery->id,
                                ]))
                                <div class="mt-4 flex flex-wrap justify-start gap-2 sm:justify-end">
                                    <x-signal.ui.button
                                        :href="$deliveryDialogUrl"
                                        data-modal-trigger="{{ $deliveryDialogId }}"
                                        data-modal-content-url="{{ $deliveryContentUrl }}"
                                        data-modal-history-url="{{ $deliveryDialogUrl }}"
                                        aria-controls="{{ $deliveryDialogId }}"
                                        aria-expanded="{{ $selectedWebhookDelivery?->id === $delivery->id ? 'true' : 'false' }}"
                                        variant="primary"
                                        class="ui-btn-sm"
                                    >{{ __('Inspect delivery') }}</x-signal.ui.button>
                                </div>
                            </article>
                        @endforeach
                    </x-signal.ui.panel>
                    <div class="mt-4">{{ $webhookDeliveries->links() }}</div>
                @endif
            </div>
            </div>
        </details>
    </x-signal.ui.panel>

    @if ($selectedWebhookDelivery && ! $webhookDeliveries->contains('id', $selectedWebhookDelivery->id))
        <a
            href="{{ $deliveryDialogHistoryUrl }}"
            data-modal-trigger="{{ $deliveryDialogId }}"
            data-modal-content-url="{{ $deliveryDialogContentUrl }}"
            data-modal-history-url="{{ $deliveryDialogHistoryUrl }}"
            aria-controls="{{ $deliveryDialogId }}"
            aria-expanded="{{ $deliveryDialogOpen ? 'true' : 'false' }}"
            class="sr-only"
        >{{ __('Open webhook delivery') }}</a>
    @endif

    <x-signal.overlays.modal
        id="{{ $deliveryDialogId }}"
        :title="__('Webhook delivery')"
        :description="__('Inspect accepted delivery evidence without leaving this repository.')"
        :open="$deliveryDialogOpen"
        body-class="p-0"
    >
        <div data-modal-content>
            <p class="p-5 text-sm text-muted">{{ __('Loading delivery evidence…') }}</p>
        </div>
    </x-signal.overlays.modal>

    <!--
     ! ------------------------------------------------------------
     ! Repository information
     ! ------------------------------------------------------------
     !-->
    <x-signal.ui.panel as="section" id="repository-information" class="ui-panel mt-6 scroll-mt-24 p-5 sm:p-6" data-repository-information aria-labelledby="repository-information-heading">
        <h2 id="repository-information-heading" class="sr-only">{{ __('Repository information') }}</h2>
        <div class="grid gap-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
        <div class="flex items-start gap-3 text-muted">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-muted" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#external-link"></use>
            </svg>
            <div>
            <span class="ui-eyebrow">
                {{ __('URL') }}
            </span>
                <div class="mt-1 break-all font-mono text-xs">
                    {{ $repository->url }}
                </div>
            </div>
        </div>
        <div class="flex items-start gap-3 text-muted">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-muted" aria-hidden="true">
                <use xlink:href="/assets/images/icons.svg#external-link"></use>
            </svg>
            <div>
                <span class="ui-eyebrow">{{ __('Branch') }}</span>
                <span class="mt-1 block font-mono text-xs">{{ $repository->branch }}</span>
            </div>
        </div>
        @if ($repository->build_commands)
            <div class="flex items-start gap-3 text-muted">
                <span class="mt-0.5" style="color: var(--ui-success)" aria-hidden="true">✓</span>
                <span>{{ __('Build hook configured') }}</span>
            </div>
        @endif
        @if ($repository->post_deployment_commands)
            <div class="flex items-start gap-3 text-muted">
                <span class="mt-0.5" style="color: var(--ui-success)" aria-hidden="true">✓</span>
                <span>{{ __('Post-deployment hook configured') }}</span>
            </div>
        @endif
        </div>
    </x-signal.ui.panel>

    <x-signal.ui.panel as="details"
        id="repository-setup"
        class="ui-responsive-details group ui-panel mt-6 scroll-mt-24 overflow-hidden"
        :open="$repositorySetupNeedsAttention"
        data-responsive-details
        data-responsive-details-mobile-expanded="{{ $repositorySetupNeedsAttention ? 'true' : 'false' }}"
    >
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-ink [&::-webkit-details-marker]:hidden">
            <span>
                <span class="ui-eyebrow block">{{ __('Deployment timeline') }}</span>
                <span class="mt-1 block text-lg">{{ __('Deployment timeline') }}</span>
                <span class="mt-1 block text-sm font-normal text-muted">
                    {{ $latestBuild ? __('Latest build: :status', ['status' => str($latestBuild->status)->replace('_', ' ')->headline()]) : __('No deployment has started yet.') }}
                </span>
            </span>
            <span class="text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <div class="ui-responsive-details__content border-t border-line p-5">
            <livewire:repository-deployment-timeline :model="$repository"></livewire:repository-deployment-timeline>
        </div>
    </x-signal.ui.panel>

    <x-signal.ui.panel as="details"
        id="repository-deployment-insights"
        class="ui-responsive-details group ui-panel mt-10 scroll-mt-24 overflow-hidden"
        :open="$deploymentInsightsNeedAttention"
        data-responsive-details
        data-responsive-details-mobile-expanded="{{ $deploymentInsightsNeedAttention ? 'true' : 'false' }}"
    >
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-ink [&::-webkit-details-marker]:hidden">
            <span>
                <span class="ui-eyebrow block">{{ __('Insights') }}</span>
                <span class="mt-1 block text-lg">{{ __('Deployment insights') }}</span>
                <span class="mt-1 block text-sm font-normal text-muted">
                    {{ trans_choice(':count recorded deployment|:count recorded deployments', $deploymentMetrics['total'], ['count' => $deploymentMetrics['total']]) }}
                    @if ($deploymentMetrics['success_rate'] !== null)
                        · {{ __(':rate% completed success', ['rate' => $deploymentMetrics['success_rate']]) }}
                    @endif
                </span>
            </span>
            <span class="text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <section class="ui-responsive-details__content border-t border-line p-5" aria-labelledby="deployment-insights-heading">
            <div>
                <h2 id="deployment-insights-heading" class="text-2xl font-extrabold text-ink">{{ __('Deployment insights') }}</h2>
                <p class="mt-1 text-sm text-muted">
                    {{ __('Outcome totals cover all recorded deployments. Median duration uses up to the 20 most recent deployments with valid start and finish times.') }}
                </p>
            </div>
            <dl class="ui-insight-grid mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-signal.ui.card as="a" tone="interactive" class="p-4" href="{{ route('builds.index', ['repository_id' => $repository->id]) }}">
                <dt class="ui-stat__label">{{ __('Total deployments') }}</dt>
                <dd class="ui-stat__value">{{ $deploymentMetrics['total'] }}</dd>
            </x-signal.ui.card>
            <x-signal.ui.card as="a" tone="interactive" class="p-4" href="{{ route('builds.index', ['repository_id' => $repository->id, 'status' => \App\Modules\Deployer\Models\Build::STATUS_SUCCEEDED]) }}">
                <dt class="ui-stat__label">{{ __('Succeeded') }}</dt>
                <dd class="mt-1 text-2xl font-bold" style="color: var(--ui-success)">{{ $deploymentMetrics['succeeded'] }}</dd>
            </x-signal.ui.card>
            <x-signal.ui.card as="a" tone="interactive" class="p-4" href="{{ route('builds.index', ['repository_id' => $repository->id, 'status' => \App\Modules\Deployer\Models\Build::STATUS_FAILED]) }}">
                <dt class="ui-stat__label">{{ __('Failed') }}</dt>
                <dd class="mt-1 text-2xl font-bold" style="color: var(--ui-danger)">{{ $deploymentMetrics['failed'] }}</dd>
            </x-signal.ui.card>
            <x-signal.ui.card class="p-4">
                <dt class="ui-stat__label">{{ __('Completed-run success rate') }}</dt>
                <dd class="ui-stat__value">
                    {{ $deploymentMetrics['success_rate'] !== null ? $deploymentMetrics['success_rate'].'%' : __('Not available') }}
                </dd>
                <p class="mt-1 text-xs text-muted">{{ __('Canceled and active runs are excluded.') }}</p>
            </x-signal.ui.card>
            <x-signal.ui.card class="p-4">
                <dt class="ui-stat__label">{{ __('Recent median duration') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-ink">
                    {{ $deploymentMetrics['median_duration_seconds'] !== null ? \App\Modules\Deployer\Models\Build::formatDuration($deploymentMetrics['median_duration_seconds']) : __('Not recorded') }}
                </dd>
                <p class="mt-1 text-xs text-muted">
                    {{ trans_choice(':count timed deployment|:count timed deployments', $deploymentMetrics['duration_sample_size'], ['count' => $deploymentMetrics['duration_sample_size']]) }}
                </p>
            </x-signal.ui.card>
            </dl>
        </section>
    </x-signal.ui.panel>

    <x-signal.ui.panel as="details"
        id="repository-deployment-history"
        class="group ui-panel mt-10 scroll-mt-24 overflow-hidden"
        :open="$latestBuild?->statusEnum()?->isActive() === true"
    >
        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 font-bold text-ink [&::-webkit-details-marker]:hidden">
            <span>
                <span class="ui-eyebrow block">{{ __('Deployments') }}</span>
                <span class="mt-1 block text-2xl">{{ __('Recent deployment history') }}</span>
                <span class="mt-1 block text-sm font-normal text-muted">{{ trans_choice(':count recent deployment|:count recent deployments', $builds->count(), ['count' => $builds->count()]) }}</span>
            </span>
            <span class="text-xl font-normal text-muted transition group-open:rotate-45" aria-hidden="true">+</span>
        </summary>
        <div class="border-t border-line p-5 pt-4">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 id="deployment-history-title" class="sr-only">{{ __('Deployment history') }}</h2>
                <x-signal.ui.button :href="route('builds.index', ['repository_id' => $repository->id])" variant="secondary" class="ui-btn-sm ml-auto">
                    {{ __('View all deployments') }}
                </x-signal.ui.button>
            </div>
            @forelse ($builds as $build)
                <x-signal.ui.card class="mb-3 flex items-center justify-between gap-4 p-4">
                    <div>
                        <a href="{{ route('builds.show', $build) }}" class="ui-link font-medium">
                            {{ __('Build #:id', ['id' => $build->id]) }}
                        </a>
                        <p class="text-sm text-muted">
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
                    @if ($build->status === \App\Modules\Deployer\Models\Build::STATUS_SUCCEEDED)
                        <x-signal.ui.badge tone="success">{{ str($build->status)->replace('_', ' ') }}</x-signal.ui.badge>
                    @elseif ($build->status === \App\Modules\Deployer\Models\Build::STATUS_FAILED)
                        <x-signal.ui.badge tone="danger">{{ str($build->status)->replace('_', ' ') }}</x-signal.ui.badge>
                    @elseif (in_array($build->status, [\App\Modules\Deployer\Models\Build::STATUS_CANCELED, \App\Modules\Deployer\Models\Build::STATUS_TIMING_OUT], true))
                        <x-signal.ui.badge tone="warning">{{ str($build->status)->replace('_', ' ') }}</x-signal.ui.badge>
                    @elseif (in_array($build->status, [\App\Modules\Deployer\Models\Build::STATUS_DEPLOYING, \App\Modules\Deployer\Models\Build::STATUS_RUNNING], true))
                        <x-signal.ui.badge tone="accent">{{ str($build->status)->replace('_', ' ') }}</x-signal.ui.badge>
                    @else
                        <x-signal.ui.badge>{{ str($build->status)->replace('_', ' ') }}</x-signal.ui.badge>
                    @endif
                </x-signal.ui.card>
            @empty
                <x-signal.ui.empty-state
                    :title="__('No deployments yet')"
                    :description="__('Deploy this repository to create its first build.')"
                />
            @endforelse
        </div>
    </x-signal.ui.panel>

    <x-scenes.repositories.edit-dialog
        :repository="$repository"
        :providers="$providers"
        :websites="$websites"
        :open="$repositoryEditOpen"
        :cancel-url="$repositoryPageUrl"
    />

    <x-scenes.websites.edit-dialog
        :website="$repository->website"
        :servers="$websiteEditServers"
        :open="$websiteEditOpen"
        :cancel-url="$repositoryPageUrl"
        :content-url="$websiteEditContentUrl"
    />
</x-layouts.app>
