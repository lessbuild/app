<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('projects.show', $project)" :title="__('Back to application')" />
    <x-layouts.partials.heading icon="view-grid" :title="__('Application configuration')" :description="__('Review portable configuration before applying changes.')" />
    @if(isset($environmentOverview))
        @php
            $recordedDependencyCount = $environmentOverview->sum(fn ($environment) => count($environment->dependencies));
            $maskedSecretCount = $environmentOverview->sum(fn ($environment) => $environment->secretCount);
        @endphp
        <x-ui.insights
            id="configuration-insights"
            class="mt-6"
            :summary="__('Recorded local state for :count environments', ['count' => $environmentOverview->count()])"
        >
            <dl class="ui-insight-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.stat
                    :label="__('Environments')"
                    :value="$environmentOverview->count()"
                    :description="__('Environment records in this application.')"
                />
                <x-ui.stat
                    :label="__('Dependencies')"
                    :value="$recordedDependencyCount"
                    :description="__('Recorded processes, resources and deployments.')"
                />
                <x-ui.stat
                    :label="__('Masked secrets')"
                    :value="$maskedSecretCount"
                    :description="__('Secret values remain hidden from this overview.')"
                />
                <x-ui.stat
                    :label="__('Recent receipts')"
                    :value="$recentApplications->count()"
                    :description="__('Recent local configuration applications available for recovery.')"
                />
            </dl>
        </x-ui.insights>
    @endif
    @if($errors->any())
        <x-ui.alert tone="danger" class="mt-4" role="alert">
            <ul class="list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif
    @if(isset($reviewError))
        <x-ui.alert tone="danger" class="mt-6" role="alert">
            <h2 class="font-bold text-primary">{{ __('This review cannot be applied') }}</h2>
            <p class="mt-3 text-secondary">{{ $reviewError }}</p>
            <p class="mt-3 text-secondary">{{ __('No changes were applied. Start a new review using the current configuration.') }}</p>
        </x-ui.alert>
    @elseif($application)
        <x-ui.card class="mt-6 p-5 sm:p-6">
            <h2 class="font-bold text-primary">{{ __('Application receipt') }} #{{ $application->id }}</h2>
            <p class="mt-3 text-secondary">{{ $application->status }}</p>
            <p class="mt-3 text-secondary">{{ __('Local configuration is saved. Only a succeeded deployment confirms remote completion.') }}</p>
            @foreach($application->relatedOperations()->with(['retry', 'build'])->orderBy('id')->get() as $operation)
                <div class="mt-4 border-t border-primary pt-3">
                    <p class="flex flex-wrap items-center gap-2 text-secondary"><span>{{ $operation->environment_slug }}</span><x-ui.badge>{{ $operation->status }}</x-ui.badge>@if($operation->failure_code)<span>· {{ $operation->failure_code }}</span>@endif</p>
                    @if($operation->retry)<p class="mt-2 text-sm text-secondary">{{ __('Retried by operation') }} #{{ $operation->retry->id }}</p>
                    @elseif((int) $operation->application->review->requested_by === (int) auth()->id() && (($operation->status === 'failed' && $operation->build_id) || $operation->status === 'canceled'))
                        <p class="mt-2 text-sm text-secondary">{{ __('Retry creates one replacement deployment using the exact failed configuration and secret snapshot. Current repository, configuration, access and deployment gates are checked again. Required approval must be granted again.') }}</p>
                        <form method="POST" action="{{ route('projects.configuration.retry', [$project, $review, $operation]) }}" class="mt-3">@csrf<x-ui.button type="submit" variant="primary">{{ $operation->status === 'canceled' ? __('Retry canceled deployment') : __('Retry failed deployment') }}</x-ui.button></form>
                    @endif
                    @if(! $operation->retry && ! in_array($operation->status, ['succeeded', 'failed', 'canceled'], true) && (! $operation->build_id || in_array($operation->build?->status, ['queued', 'awaiting_approval'], true)))
                        <p class="mt-2 text-sm text-secondary">{{ __('Cancel stops this pending deployment intent. Saved local configuration and remote services are preserved.') }}</p>
                        <form method="POST" action="{{ route('projects.configuration.cancel', [$project, $review, $operation]) }}" class="mt-3">@csrf<x-ui.button type="submit" variant="secondary">{{ __('Cancel pending deployment') }}</x-ui.button></form>
                    @endif
                </div>
            @endforeach
        </x-ui.card>
    @elseif($review)
        <x-ui.card class="mt-6 p-5 sm:p-6">
            <h2 class="font-bold text-primary">{{ __('Review changes') }}</h2>
            <p class="mt-2 text-secondary">{{ __('Omitted objects are preserved. Resource detachment does not delete remote data.') }}</p>
            @if(collect($plan['changes'])->contains(fn ($change) => $change['kind'] === 'environment' && $change['action'] === 'remove'))
                <x-ui.alert tone="warning" class="mt-3" role="note">{{ __('Environment removal deletes the listed local configuration and secret-version history only. Websites, servers, running services and remote data remain untouched; this does not stop workloads or reduce provider charges.') }}</x-ui.alert>
            @endif
            <div class="mt-4 divide-y divide-primary rounded-lg border border-primary" aria-label="{{ __('Reviewed configuration changes') }}">
                @foreach($plan['changes'] as $change)
                    <article data-configuration-change class="p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-primary">{{ $change['name'] }}</h3>
                                <p class="mt-1 text-xs text-secondary">{{ $change['environment'] }} / {{ $change['kind'] }}</p>
                            </div>
                            <x-ui.badge>{{ ucfirst(str_replace('_', ' ', $change['action'])) }}</x-ui.badge>
                        </div>
                        @if($change['kind'] === 'deployment')<p class="mt-3 text-xs text-secondary">{{ ($change['requires_approval'] ?? false) ? __('Approval required before deployment') : __('Queued after local apply; not immediate remote success') }}</p>@endif
                        @if($change['action'] === 'detach')<p class="mt-3 text-xs text-secondary">{{ __('Remote data preserved') }}</p>@endif
                        <p class="mt-3 text-sm text-secondary"><span class="font-semibold text-primary">{{ __('Reviewed fields') }}:</span> {{ $change['fields'] === [] ? '—' : implode(', ', $change['fields']) }}</p>
                    </article>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-secondary">{{ __('Command and credential values are hidden. Fields identify the settings under review, not a plaintext diff.') }}</p>
            <p class="mt-4 text-secondary">{{ __('Expires') }}: {{ $review->expires_at->toIso8601String() }}</p>
            @if($plan['apply_available'])<form method="POST" action="{{ route('projects.configuration.apply', [$project, $review]) }}" class="mt-4">@csrf<x-ui.button type="submit" variant="primary">{{ __('Apply reviewed configuration') }}</x-ui.button></form>@else<p class="mt-4 text-secondary">{{ __('Explicit adoption is required. Submit an updated document for a new review.') }}</p>@endif
        </x-ui.card>
    @else
        @if($recentApplications->isNotEmpty())
            <x-ui.card class="mt-6 p-5">
                <h2 class="font-bold text-primary">{{ __('Recent application receipts') }}</h2>
                <p class="mt-2 text-secondary">{{ __('Open a receipt to refresh deployment status and recover a pending or failed operation.') }}</p>
                <ul class="mt-3 space-y-2">
                    @foreach($recentApplications as $receipt)
                        <li><a class="text-primary underline" href="{{ route('projects.configuration.review', [$project, $receipt->configuration_review_id]) }}">{{ __('Application receipt') }} #{{ $receipt->id }} · {{ $receipt->status }}</a></li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
        <x-ui.card class="mt-6 p-5" aria-labelledby="environment-overview-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="environment-overview-heading" class="font-bold text-primary">{{ __('Current environment overview') }}</h2>
                    <p class="mt-2 text-sm text-secondary">{{ __('Recorded local state for this application. It does not query or claim to represent remote provider drift.') }}</p>
                </div>
                <x-ui.badge>{{ trans_choice(':count environment|:count environments', $environmentOverview->count(), ['count' => $environmentOverview->count()]) }}</x-ui.badge>
            </div>
            @if($environmentOverview->isNotEmpty())
                <div class="mt-4 space-y-3" aria-label="{{ __('Recorded environment dependencies') }}">
                    @foreach($environmentOverview as $environment)
                        <article data-configuration-environment class="rounded-xl border border-primary bg-secondary p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="flex flex-wrap items-center gap-2 font-bold text-primary">{{ $environment->name }} @if($environment->isProtected)<x-ui.badge tone="accent">{{ __('Protected') }}</x-ui.badge>@endif</h3>
                                    <p class="mt-1 text-xs text-secondary">{{ ucfirst($environment->type) }} · {{ $environment->branch }} · {{ $environment->status }}</p>
                                </div>
                                <form method="GET" action="{{ route('projects.configuration.observe', $project) }}"><input type="hidden" name="environment_id" value="{{ $environment->id }}"><x-ui.button type="submit" variant="secondary">{{ __('Observe provider') }}</x-ui.button></form>
                            </div>
                            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                <div><dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Runtime') }}</dt><dd class="mt-1 text-primary">{{ ucfirst($environment->runtimeType) }}</dd></div>
                                <div><dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Configuration') }}</dt><dd class="mt-1 text-primary">{{ $environment->processCount }} {{ __('process(es)') }} · {{ $environment->resourceCount }} {{ __('resource(s)') }} · {{ $environment->variableCount }} {{ __('variable(s)') }}<span class="mt-1 block text-xs text-secondary">{{ $environment->secretCount }} {{ __('secret value(s) masked') }}</span></dd></div>
                                <div class="sm:col-span-2"><dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Recorded dependencies') }}</dt><dd class="mt-1"><ul class="space-y-1">@foreach($environment->dependencies as $dependency)<li><span class="font-medium text-primary">{{ ucfirst($dependency['kind']) }}:</span> {{ $dependency['name'] }} <span class="text-secondary">· {{ str_replace('_', ' ', $dependency['status']) }} · {{ $dependency['detail'] }}</span></li>@endforeach</ul></dd></div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm text-secondary">{{ __('No environments have been recorded yet.') }}</p>
            @endif
            @if($environmentOverview->count() > 1)
                @php($fromEnvironmentId = isset($comparison) ? $comparison->from->id : (int) request()->query('from_environment_id'))
                @php($toEnvironmentId = isset($comparison) ? $comparison->to->id : (int) request()->query('to_environment_id'))
                <form method="GET" action="{{ route('projects.configuration.compare', $project) }}" class="mt-5 grid gap-3 border-t border-primary pt-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                    <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Compare from') }}</span><select name="from_environment_id" class="input secondary w-full rounded-lg" required>@foreach($environmentOverview as $environment)<option value="{{ $environment->id }}" @selected($fromEnvironmentId === $environment->id)>{{ $environment->name }}</option>@endforeach</select></label>
                    <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Compare to') }}</span><select name="to_environment_id" class="input secondary w-full rounded-lg" required>@foreach($environmentOverview as $environment)<option value="{{ $environment->id }}" @selected($toEnvironmentId === $environment->id)>{{ $environment->name }}</option>@endforeach</select></label>
                    <x-ui.button type="submit" variant="secondary">{{ __('Compare recorded state') }}</x-ui.button>
                </form>
            @endif
        </x-ui.card>
        @isset($observation)
            <x-ui.card class="mt-6 p-5" aria-labelledby="environment-observation-heading">
                <h2 id="environment-observation-heading" class="font-bold text-primary">{{ __('Observed provider state') }}</h2>
                <p class="mt-2 text-sm text-secondary">{{ __('One-time read for :environment through :provider. This is observed remote state, separate from desired configuration and :app’s recorded local state.', ['environment' => $observation->environmentName, 'provider' => $observation->providerName, 'app' => config('app.name')]) }}</p>
                <p class="mt-3 text-sm text-secondary">{{ $observation->message }}</p>
                <p class="mt-3 text-sm font-bold text-primary">{{ __('Provider readiness: :status', ['status' => str($observation->providerReadiness)->replace('_', ' ')->headline()]) }}</p>
                @if($observation->providerState)<p class="mt-1 text-xs text-secondary">{{ __('Provider lifecycle: :state', ['state' => $observation->providerState]) }}</p>@endif
                @if($observation->status === \App\Data\ApplicationEnvironmentObservation::STATUS_OBSERVED)
                    <div class="mt-4 divide-y divide-primary rounded-lg border border-primary" aria-label="{{ __('Observed provider server fields') }}">
                        @foreach($observation->fields as $field)
                            <article data-configuration-observation-field class="p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <h3 class="font-semibold text-primary">{{ $field['field'] }}</h3>
                                    <x-ui.badge :tone="$field['status'] === 'match' ? 'success' : 'warning'">{{ $field['status'] === 'match' ? __('Matches') : __('Different') }}</x-ui.badge>
                                </div>
                                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Recorded locally') }}</dt><dd class="mt-1 text-secondary">{{ $field['recorded'] }}</dd></div><div><dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Observed at provider') }}</dt><dd class="mt-1 text-secondary">{{ $field['observed'] }}</dd></div></dl>
                            </article>
                        @endforeach
                    </div>
                    @if($observation->hasDifferences())<p class="mt-3 text-xs text-secondary">{{ __('A difference is informational only. Corrective changes must go through the existing configuration review and apply workflow.') }}</p>@endif
                @endif
            </x-ui.card>
        @endisset
        @isset($comparison)
            <x-ui.card class="mt-6 p-5" aria-labelledby="environment-comparison-heading">
                <h2 id="environment-comparison-heading" class="font-bold text-primary">{{ __('Recorded environment comparison') }}</h2>
                <p class="mt-2 text-sm text-secondary">{{ __('This compares :app’s recorded local metadata only. It does not query provider state or prove remote drift. Desired configuration changes still require a review and apply.', ['app' => config('app.name')]) }}</p>
                <p class="mt-3 text-sm font-bold text-primary">{{ $comparison->from->name }} <span class="font-normal text-secondary">→</span> {{ $comparison->to->name }}</p>
                @if($comparison->isIdentical())
                    <p class="mt-4 rounded-lg border border-primary bg-secondary p-3 text-sm text-secondary">{{ __('All displayed recorded fields match. Commands, variable keys and values, and encrypted resource configuration are not compared.') }}</p>
                @else
                    <div class="mt-4 divide-y divide-primary rounded-lg border border-primary" aria-label="{{ __('Safe recorded environment differences') }}">
                        @foreach($comparison->differences as $difference)
                            <article data-configuration-comparison-field class="p-4">
                                <h3 class="font-semibold text-primary">{{ $difference['field'] }}</h3>
                                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ $comparison->from->name }}</dt><dd class="mt-1 text-secondary">{{ $difference['from'] }}</dd></div><div><dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ $comparison->to->name }}</dt><dd class="mt-1 text-secondary">{{ $difference['to'] }}</dd></div></dl>
                            </article>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs text-secondary">{{ __('Only non-secret metadata is shown. Executable commands, variable keys and values, and encrypted resource configuration are excluded.') }}</p>
                @endif
            </x-ui.card>
        @endisset
        <details class="ui-card mt-6 p-5">
            <summary class="cursor-pointer font-bold text-primary">{{ __('Version 2 authoring guide') }}</summary>
            <p class="mt-3 text-sm text-secondary">{{ __('Start with the parser-valid example below, replace the illustrative binding ID with a workspace ID from the catalog, then add only the sections you need. Invalid submissions are rejected without retaining this form in session input.') }}</p>
            <div class="mt-4 grid gap-5 lg:grid-cols-2">
                <section>
                    <h2 class="font-bold text-primary">{{ __('Starter YAML') }}</h2>
                    <pre class="mt-2 overflow-x-auto rounded-lg border border-primary bg-secondary p-3 text-xs text-primary" tabindex="0"><code>{{ $authoringGuide['document'] }}</code></pre>
                </section>
                <section>
                    <h2 class="font-bold text-primary">{{ __('Starter bindings JSON') }}</h2>
                    <pre class="mt-2 overflow-x-auto rounded-lg border border-primary bg-secondary p-3 text-xs text-primary" tabindex="0"><code>{{ $authoringGuide['bindings'] }}</code></pre>
                </section>
            </div>
            <ul class="mt-4 space-y-2 text-sm text-secondary">
                @foreach($authoringGuide['fields'] as $field)
                    <li><code class="text-primary">{{ $field['path'] }}</code> — {{ $field['description'] }}</li>
                @endforeach
            </ul>
        </details>
        <details class="ui-card mt-6 p-5">
            <summary class="cursor-pointer font-bold text-primary">{{ __('Find workspace binding IDs') }}</summary>
            <p class="mt-3 text-secondary">{{ __('Use these IDs in the JSON bindings below. Secret values are never shown.') }}</p>
            <div class="mt-4 grid gap-6 lg:grid-cols-3">
                <section><h2 class="font-bold text-primary">{{ __('Websites · placements') }}</h2><ul class="mt-2 space-y-2">@forelse($websites as $site)<li class="text-secondary">#{{ $site->id }} · {{ $site->name }} · {{ $site->url }}</li>@empty<li class="text-secondary">{{ __('No websites available.') }}</li>@endforelse</ul>{{ $websites->withQueryString()->links() }}</section>
                <section><h2 class="font-bold text-primary">{{ __('Secrets · secrets') }}</h2><ul class="mt-2 space-y-2">@forelse($secrets as $secret)<li class="text-secondary">#{{ $secret->id }} · {{ $secret->key }} · {{ $secret->environment->name }} · {{ $secret->scope }}</li>@empty<li class="text-secondary">{{ __('No secret sources available.') }}</li>@endforelse</ul>{{ $secrets->withQueryString()->links() }}</section>
                <section><h2 class="font-bold text-primary">{{ __('Repositories · repositories') }}</h2><ul class="mt-2 space-y-2">@forelse($repositories as $repository)<li class="text-secondary">#{{ $repository->id }} · {{ $repository->name }} · {{ $repository->branch }} · {{ __('Website') }} #{{ $repository->website_id }}</li>@empty<li class="text-secondary">{{ __('No repositories available.') }}</li>@endforelse</ul>{{ $repositories->withQueryString()->links() }}</section>
            </div>
        </details>
        <form method="POST" action="{{ route('projects.configuration.store', $project) }}" class="mt-6 space-y-5">@csrf
            <label class="block"><span class="mb-2 block text-primary">{{ __('Version 2 YAML document') }}</span><textarea required name="document" rows="16" class="input secondary rounded-lg font-mono" spellcheck="false"></textarea></label>
            <label class="block"><span class="mb-2 block text-primary">{{ __('Workspace bindings (JSON)') }}</span><textarea required name="bindings" rows="5" class="input secondary rounded-lg font-mono" spellcheck="false" placeholder='{"placements":{"site":1},"secrets":{},"repositories":{}}'></textarea></label>
            <p class="text-secondary">{{ __('Use existing website, secret-variable and repository IDs from this workspace. Do not paste secret values. Inputs are not retained after a validation error.') }}</p>
            <x-ui.button type="submit" variant="primary">{{ __('Create review') }}</x-ui.button>
        </form>
    @endif
    <a class="mt-6 inline-block text-secondary" href="{{ route('projects.configuration.create', $project) }}">{{ __('Start a new review') }}</a>
</x-layouts.app>
