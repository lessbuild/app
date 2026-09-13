<x-layouts.app>
    <x-layouts.partials.breadcrumbs :route="route('projects.show', $project)" :title="__('Back to application')" />
    <div class="mt-6"><x-layouts.partials.heading icon="view-grid" :title="__('Application configuration')" :description="__('Review portable configuration before applying changes.')" /></div>
    @foreach($errors->all() as $error)<p role="alert" class="mt-3 text-primary">{{ $error }}</p>@endforeach
    @if(isset($reviewError))
        <section class="mt-6 rounded-xl border border-primary bg-primary p-6" role="alert">
            <h2 class="font-bold text-primary">{{ __('This review cannot be applied') }}</h2>
            <p class="mt-3 text-secondary">{{ $reviewError }}</p>
            <p class="mt-3 text-secondary">{{ __('No changes were applied. Start a new review using the current configuration.') }}</p>
        </section>
    @elseif($application)
        <section class="mt-6 rounded-xl border border-primary bg-primary p-6">
            <h2 class="font-bold text-primary">{{ __('Application receipt') }} #{{ $application->id }}</h2>
            <p class="mt-3 text-secondary">{{ $application->status }}</p>
            <p class="mt-3 text-secondary">{{ __('Local configuration is saved. Only a succeeded deployment confirms remote completion.') }}</p>
            @foreach($application->relatedOperations()->with(['retry', 'build'])->orderBy('id')->get() as $operation)
                <div class="mt-4 border-t border-primary pt-3">
                    <p class="text-secondary">{{ $operation->environment_slug }} · {{ $operation->status }} @if($operation->failure_code) · {{ $operation->failure_code }} @endif</p>
                    @if($operation->retry)<p class="mt-2 text-sm text-secondary">{{ __('Retried by operation') }} #{{ $operation->retry->id }}</p>
                    @elseif((int) $operation->application->review->requested_by === (int) auth()->id() && (($operation->status === 'failed' && $operation->build_id) || $operation->status === 'canceled'))
                        <p class="mt-2 text-sm text-secondary">{{ __('Retry creates one replacement deployment using the exact failed configuration and secret snapshot. Current repository, configuration, access and deployment gates are checked again. Required approval must be granted again.') }}</p>
                        <form method="POST" action="{{ route('projects.configuration.retry', [$project, $review, $operation]) }}" class="mt-3">@csrf<button class="button primary" type="submit">{{ $operation->status === 'canceled' ? __('Retry canceled deployment') : __('Retry failed deployment') }}</button></form>
                    @endif
                    @if(! $operation->retry && ! in_array($operation->status, ['succeeded', 'failed', 'canceled'], true) && (! $operation->build_id || in_array($operation->build?->status, ['queued', 'awaiting_approval'], true)))
                        <p class="mt-2 text-sm text-secondary">{{ __('Cancel stops this pending deployment intent. Saved local configuration and remote services are preserved.') }}</p>
                        <form method="POST" action="{{ route('projects.configuration.cancel', [$project, $review, $operation]) }}" class="mt-3">@csrf<button class="button secondary" type="submit">{{ __('Cancel pending deployment') }}</button></form>
                    @endif
                </div>
            @endforeach
        </section>
    @elseif($review)
        <section class="mt-6 rounded-xl border border-primary bg-primary p-6">
            <h2 class="font-bold text-primary">{{ __('Review changes') }}</h2>
            <p class="mt-2 text-secondary">{{ __('Omitted objects are preserved. Resource detachment does not delete remote data.') }}</p>
            @if(collect($plan['changes'])->contains(fn ($change) => $change['kind'] === 'environment' && $change['action'] === 'remove'))
                <p class="mt-3 rounded-lg border border-amber-500 p-3 text-primary" role="note">{{ __('Environment removal deletes the listed local configuration and secret-version history only. Websites, servers, running services and remote data remain untouched; this does not stop workloads or reduce provider charges.') }}</p>
            @endif
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ __('Reviewed configuration changes') }}</caption>
                    <thead class="border-b border-primary text-secondary"><tr><th scope="col" class="p-3">{{ __('Object') }}</th><th scope="col" class="p-3">{{ __('Action') }}</th><th scope="col" class="p-3">{{ __('Reviewed fields') }}</th></tr></thead>
                    <tbody>
                        @foreach($plan['changes'] as $change)
                            <tr class="border-b border-primary align-top text-primary">
                                <th scope="row" class="p-3 font-medium"><span class="block">{{ $change['name'] }}</span><span class="text-xs text-secondary">{{ $change['environment'] }} / {{ $change['kind'] }}</span></th>
                                <td class="p-3">{{ ucfirst(str_replace('_', ' ', $change['action'])) }}
                                    @if($change['kind'] === 'deployment')<span class="mt-1 block text-xs text-secondary">{{ ($change['requires_approval'] ?? false) ? __('Approval required before deployment') : __('Queued after local apply; not immediate remote success') }}</span>@endif
                                    @if($change['action'] === 'detach')<span class="mt-1 block text-xs text-secondary">{{ __('Remote data preserved') }}</span>@endif
                                </td>
                                <td class="p-3 text-secondary">{{ $change['fields'] === [] ? '—' : implode(', ', $change['fields']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-secondary">{{ __('Command and credential values are hidden. Fields identify the settings under review, not a plaintext diff.') }}</p>
            <p class="mt-4 text-secondary">{{ __('Expires') }}: {{ $review->expires_at->toIso8601String() }}</p>
            @if($plan['apply_available'])<form method="POST" action="{{ route('projects.configuration.apply', [$project, $review]) }}" class="mt-4">@csrf<button class="button primary" type="submit">{{ __('Apply reviewed configuration') }}</button></form>@else<p class="mt-4 text-secondary">{{ __('Explicit adoption is required. Submit an updated document for a new review.') }}</p>@endif
        </section>
    @else
        @if($recentApplications->isNotEmpty())
            <section class="mt-6 rounded-xl border border-primary bg-primary p-5">
                <h2 class="font-bold text-primary">{{ __('Recent application receipts') }}</h2>
                <p class="mt-2 text-secondary">{{ __('Open a receipt to refresh deployment status and recover a pending or failed operation.') }}</p>
                <ul class="mt-3 space-y-2">
                    @foreach($recentApplications as $receipt)
                        <li><a class="text-primary underline" href="{{ route('projects.configuration.review', [$project, $receipt->configuration_review_id]) }}">{{ __('Application receipt') }} #{{ $receipt->id }} · {{ $receipt->status }}</a></li>
                    @endforeach
                </ul>
            </section>
        @endif
        <section class="mt-6 rounded-xl border border-primary bg-primary p-5" aria-labelledby="environment-overview-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="environment-overview-heading" class="font-bold text-primary">{{ __('Current environment overview') }}</h2>
                    <p class="mt-2 text-sm text-secondary">{{ __('Recorded local state for this application. It does not query or claim to represent remote provider drift.') }}</p>
                </div>
                <span class="rounded-full bg-secondary px-3 py-1 text-xs font-bold text-secondary">{{ trans_choice(':count environment|:count environments', $environmentOverview->count(), ['count' => $environmentOverview->count()]) }}</span>
            </div>
            @if($environmentOverview->isNotEmpty())
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[48rem] text-left text-sm">
                        <caption class="sr-only">{{ __('Recorded environment dependencies') }}</caption>
                        <thead class="border-b border-primary text-secondary"><tr><th scope="col" class="p-3">{{ __('Environment') }}</th><th scope="col" class="p-3">{{ __('Runtime') }}</th><th scope="col" class="p-3">{{ __('Recorded dependencies') }}</th><th scope="col" class="p-3">{{ __('Configuration') }}</th><th scope="col" class="p-3">{{ __('Provider state') }}</th></tr></thead>
                        <tbody>
                            @foreach($environmentOverview as $environment)
                                <tr class="border-b border-primary align-top text-primary">
                                    <th scope="row" class="p-3"><span class="block font-bold">{{ $environment->name }} @if($environment->isProtected)<span class="ml-1 rounded-full bg-ternary px-2 py-0.5 text-[10px] uppercase text-white">{{ __('Protected') }}</span>@endif</span><span class="mt-1 block text-xs text-secondary">{{ ucfirst($environment->type) }} · {{ $environment->branch }} · {{ $environment->status }}</span></th>
                                    <td class="p-3 text-secondary">{{ ucfirst($environment->runtimeType) }}</td>
                                    <td class="p-3"><ul class="space-y-1">@foreach($environment->dependencies as $dependency)<li><span class="font-medium text-primary">{{ ucfirst($dependency['kind']) }}:</span> {{ $dependency['name'] }} <span class="text-secondary">· {{ str_replace('_', ' ', $dependency['status']) }} · {{ $dependency['detail'] }}</span></li>@endforeach</ul></td>
                                    <td class="p-3 text-secondary">{{ $environment->processCount }} {{ __('process(es)') }} · {{ $environment->resourceCount }} {{ __('resource(s)') }} · {{ $environment->variableCount }} {{ __('variable(s)') }}<br><span class="text-xs">{{ $environment->secretCount }} {{ __('secret value(s) masked') }}</span></td>
                                    <td class="p-3"><form method="GET" action="{{ route('projects.configuration.observe', $project) }}"><input type="hidden" name="environment_id" value="{{ $environment->id }}"><button type="submit" class="button secondary whitespace-nowrap">{{ __('Observe provider') }}</button></form></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-4 text-sm text-secondary">{{ __('No environments have been recorded yet.') }}</p>
            @endif
            @if($environmentOverview->count() > 1)
                @php($fromEnvironmentId = isset($comparison) ? $comparison->from->id : (int) request()->query('from_environment_id'))
                @php($toEnvironmentId = isset($comparison) ? $comparison->to->id : (int) request()->query('to_environment_id'))
                <form method="GET" action="{{ route('projects.configuration.compare', $project) }}" class="mt-5 grid gap-3 border-t border-primary pt-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                    <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Compare from') }}</span><select name="from_environment_id" class="input secondary w-full rounded-sm" required>@foreach($environmentOverview as $environment)<option value="{{ $environment->id }}" @selected($fromEnvironmentId === $environment->id)>{{ $environment->name }}</option>@endforeach</select></label>
                    <label><span class="mb-1 block text-xs font-bold uppercase text-secondary">{{ __('Compare to') }}</span><select name="to_environment_id" class="input secondary w-full rounded-sm" required>@foreach($environmentOverview as $environment)<option value="{{ $environment->id }}" @selected($toEnvironmentId === $environment->id)>{{ $environment->name }}</option>@endforeach</select></label>
                    <button type="submit" class="button secondary">{{ __('Compare recorded state') }}</button>
                </form>
            @endif
        </section>
        @isset($observation)
            <section class="mt-6 rounded-xl border border-primary bg-primary p-5" aria-labelledby="environment-observation-heading">
                <h2 id="environment-observation-heading" class="font-bold text-primary">{{ __('Observed provider state') }}</h2>
                <p class="mt-2 text-sm text-secondary">{{ __('One-time read for :environment through :provider. This is observed remote state, separate from desired configuration and BuildPusher’s recorded local state.', ['environment' => $observation->environmentName, 'provider' => $observation->providerName]) }}</p>
                <p class="mt-3 text-sm text-secondary">{{ $observation->message }}</p>
                @if($observation->status === \App\Data\ApplicationEnvironmentObservation::STATUS_OBSERVED)
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[42rem] text-left text-sm">
                            <caption class="sr-only">{{ __('Observed provider server fields') }}</caption>
                            <thead class="border-b border-primary text-secondary"><tr><th scope="col" class="p-3">{{ __('Field') }}</th><th scope="col" class="p-3">{{ __('Recorded locally') }}</th><th scope="col" class="p-3">{{ __('Observed at provider') }}</th><th scope="col" class="p-3">{{ __('Result') }}</th></tr></thead>
                            <tbody>
                                @foreach($observation->fields as $field)
                                    <tr class="border-b border-primary align-top text-primary"><th scope="row" class="p-3 font-medium">{{ $field['field'] }}</th><td class="p-3 text-secondary">{{ $field['recorded'] }}</td><td class="p-3 text-secondary">{{ $field['observed'] }}</td><td class="p-3">{{ $field['status'] === 'match' ? __('Matches') : __('Different') }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($observation->hasDifferences())<p class="mt-3 text-xs text-secondary">{{ __('A difference is informational only. Corrective changes must go through the existing configuration review and apply workflow.') }}</p>@endif
                @endif
            </section>
        @endisset
        @isset($comparison)
            <section class="mt-6 rounded-xl border border-primary bg-primary p-5" aria-labelledby="environment-comparison-heading">
                <h2 id="environment-comparison-heading" class="font-bold text-primary">{{ __('Recorded environment comparison') }}</h2>
                <p class="mt-2 text-sm text-secondary">{{ __('This compares BuildPusher’s recorded local metadata only. It does not query provider state or prove remote drift. Desired configuration changes still require a review and apply.') }}</p>
                <p class="mt-3 text-sm font-bold text-primary">{{ $comparison->from->name }} <span class="font-normal text-secondary">→</span> {{ $comparison->to->name }}</p>
                @if($comparison->isIdentical())
                    <p class="mt-4 rounded-lg border border-primary bg-secondary p-3 text-sm text-secondary">{{ __('All displayed recorded fields match. Commands, variable keys and values, and encrypted resource configuration are not compared.') }}</p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[40rem] text-left text-sm">
                            <caption class="sr-only">{{ __('Safe recorded environment differences') }}</caption>
                            <thead class="border-b border-primary text-secondary"><tr><th scope="col" class="p-3">{{ __('Field') }}</th><th scope="col" class="p-3">{{ $comparison->from->name }}</th><th scope="col" class="p-3">{{ $comparison->to->name }}</th></tr></thead>
                            <tbody>
                                @foreach($comparison->differences as $difference)
                                    <tr class="border-b border-primary align-top text-primary"><th scope="row" class="p-3 font-medium">{{ $difference['field'] }}</th><td class="p-3 text-secondary">{{ $difference['from'] }}</td><td class="p-3 text-secondary">{{ $difference['to'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-xs text-secondary">{{ __('Only non-secret metadata is shown. Executable commands, variable keys and values, and encrypted resource configuration are excluded.') }}</p>
                @endif
            </section>
        @endisset
        <details class="mt-6 rounded-xl border border-primary bg-primary p-5">
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
        <details class="mt-6 rounded-xl border border-primary bg-primary p-5">
            <summary class="cursor-pointer font-bold text-primary">{{ __('Find workspace binding IDs') }}</summary>
            <p class="mt-3 text-secondary">{{ __('Use these IDs in the JSON bindings below. Secret values are never shown.') }}</p>
            <div class="mt-4 grid gap-6 lg:grid-cols-3">
                <section><h2 class="font-bold text-primary">{{ __('Websites · placements') }}</h2><ul class="mt-2 space-y-2">@forelse($websites as $site)<li class="text-secondary">#{{ $site->id }} · {{ $site->name }} · {{ $site->url }}</li>@empty<li class="text-secondary">{{ __('No websites available.') }}</li>@endforelse</ul>{{ $websites->withQueryString()->links() }}</section>
                <section><h2 class="font-bold text-primary">{{ __('Secrets · secrets') }}</h2><ul class="mt-2 space-y-2">@forelse($secrets as $secret)<li class="text-secondary">#{{ $secret->id }} · {{ $secret->key }} · {{ $secret->environment->name }} · {{ $secret->scope }}</li>@empty<li class="text-secondary">{{ __('No secret sources available.') }}</li>@endforelse</ul>{{ $secrets->withQueryString()->links() }}</section>
                <section><h2 class="font-bold text-primary">{{ __('Repositories · repositories') }}</h2><ul class="mt-2 space-y-2">@forelse($repositories as $repository)<li class="text-secondary">#{{ $repository->id }} · {{ $repository->name }} · {{ $repository->branch }} · {{ __('Website') }} #{{ $repository->website_id }}</li>@empty<li class="text-secondary">{{ __('No repositories available.') }}</li>@endforelse</ul>{{ $repositories->withQueryString()->links() }}</section>
            </div>
        </details>
        <form method="POST" action="{{ route('projects.configuration.store', $project) }}" class="mt-6 space-y-5">@csrf
            <label class="block"><span class="mb-2 block text-primary">{{ __('Version 2 YAML document') }}</span><textarea required name="document" rows="16" class="input secondary rounded-sm font-mono" spellcheck="false"></textarea></label>
            <label class="block"><span class="mb-2 block text-primary">{{ __('Workspace bindings (JSON)') }}</span><textarea required name="bindings" rows="5" class="input secondary rounded-sm font-mono" spellcheck="false" placeholder='{"placements":{"site":1},"secrets":{},"repositories":{}}'></textarea></label>
            <p class="text-secondary">{{ __('Use existing website, secret-variable and repository IDs from this workspace. Do not paste secret values. Inputs are not retained after a validation error.') }}</p>
            <button type="submit" class="button primary">{{ __('Create review') }}</button>
        </form>
    @endif
    <a class="mt-6 inline-block text-secondary" href="{{ route('projects.configuration.create', $project) }}">{{ __('Start a new review') }}</a>
</x-layouts.app>
