@php
    $applicationDialogId = 'application-configuration-dialog';
    $applicationPageUrl = route('projects.show', ['project' => $project, 'dialog' => 'application-configuration']);
    $authoringContentUrl = route('projects.configuration.dialog', $project);
    $fullPageUrl = route('projects.configuration.create', $project);
@endphp

<div data-configuration-dialog class="space-y-5 p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Application workflow') }}</p>
            <h2 class="mt-1 text-xl font-black text-primary">
                @if ($application)
                    {{ __('Application receipt') }} #{{ $application->id }}
                @elseif ($review)
                    {{ __('Review configuration changes') }}
                @else
                    {{ __('Configuration as code') }}
                @endif
            </h2>
            <p class="mt-1 text-sm text-secondary">{{ __('Review portable configuration before applying changes.') }}</p>
        </div>
        <a href="{{ $fullPageUrl }}" class="text-sm font-semibold text-ternary underline">{{ __('Open full page') }}</a>
    </div>

    @if ($errors->any())
        <x-ui.alert tone="danger" role="alert">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    @if (isset($reviewError))
        <x-ui.alert tone="danger" role="alert">
            <h3 class="font-bold text-primary">{{ __('This review cannot be applied') }}</h3>
            <p class="mt-2 text-secondary">{{ $reviewError }}</p>
            <p class="mt-2 text-secondary">{{ __('No changes were applied. Start a new review using the current configuration.') }}</p>
            <a href="{{ $applicationPageUrl }}" data-modal-trigger="{{ $applicationDialogId }}" data-modal-content-url="{{ $authoringContentUrl }}" aria-controls="{{ $applicationDialogId }}" aria-expanded="false" class="mt-3 inline-block font-semibold text-ternary underline">{{ __('Start a new review') }}</a>
        </x-ui.alert>
    @elseif ($application)
        <x-ui.card class="p-4 sm:p-5">
            <p class="text-sm font-bold text-primary">{{ __('Status: :status', ['status' => $application->status]) }}</p>
            <p class="mt-2 text-sm text-secondary">{{ __('Local configuration is saved. Only a succeeded deployment confirms remote completion.') }}</p>

            <div class="mt-4 space-y-4">
                @foreach ($application->relatedOperations()->with(['retry', 'build'])->orderBy('id')->get() as $operation)
                    <article class="border-t border-primary pt-3">
                        <p class="flex flex-wrap items-center gap-2 text-sm text-secondary">
                            <span>{{ $operation->environment_slug }}</span>
                            <x-ui.badge>{{ $operation->status }}</x-ui.badge>
                            @if ($operation->failure_code)<span>· {{ $operation->failure_code }}</span>@endif
                        </p>
                        @if ($operation->retry)
                            <p class="mt-2 text-sm text-secondary">{{ __('Retried by operation') }} #{{ $operation->retry->id }}</p>
                        @elseif ((int) $operation->application->review->requested_by === (int) auth()->id() && (($operation->status === 'failed' && $operation->build_id) || $operation->status === 'canceled'))
                            <p class="mt-2 text-sm text-secondary">{{ __('Retry creates one replacement deployment using the exact failed configuration and secret snapshot. Current gates are checked again.') }}</p>
                            <form method="POST" action="{{ route('projects.configuration.retry', ['project' => $project, 'review' => $review, 'operation' => $operation, 'dialog' => 'application-configuration']) }}" class="mt-3">
                                @csrf
                                <x-ui.button type="submit" variant="primary">{{ $operation->status === 'canceled' ? __('Retry canceled deployment') : __('Retry failed deployment') }}</x-ui.button>
                            </form>
                        @endif
                        @if (! $operation->retry && ! in_array($operation->status, ['succeeded', 'failed', 'canceled'], true) && (! $operation->build_id || in_array($operation->build?->status, ['queued', 'awaiting_approval'], true)))
                            <p class="mt-2 text-sm text-secondary">{{ __('Cancel stops this pending deployment intent. Saved local configuration and remote services are preserved.') }}</p>
                            <form method="POST" action="{{ route('projects.configuration.cancel', ['project' => $project, 'review' => $review, 'operation' => $operation, 'dialog' => 'application-configuration']) }}" class="mt-3">
                                @csrf
                                <x-ui.button type="submit" variant="secondary">{{ __('Cancel pending deployment') }}</x-ui.button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
        </x-ui.card>
        <a href="{{ $applicationPageUrl }}" data-modal-trigger="{{ $applicationDialogId }}" data-modal-content-url="{{ $authoringContentUrl }}" aria-controls="{{ $applicationDialogId }}" aria-expanded="false" class="font-semibold text-ternary underline">{{ __('Start a new review') }}</a>
    @elseif ($review)
        <x-ui.card class="p-4 sm:p-5">
            <h3 class="font-bold text-primary">{{ __('Review changes') }}</h3>
            <p class="mt-2 text-sm text-secondary">{{ __('Omitted objects are preserved. Resource detachment does not delete remote data.') }}</p>
            @if (collect($plan['changes'])->contains(fn ($change) => $change['kind'] === 'environment' && $change['action'] === 'remove'))
                <x-ui.alert tone="warning" class="mt-3" role="note">{{ __('Environment removal deletes the listed local configuration and secret-version history only. Websites, servers, running services and remote data remain untouched; this does not stop workloads or reduce provider charges.') }}</x-ui.alert>
            @endif
            <div class="mt-4 divide-y divide-primary rounded-lg border border-primary" aria-label="{{ __('Reviewed configuration changes') }}">
                @foreach ($plan['changes'] as $change)
                    <article data-configuration-change class="p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h4 class="font-semibold text-primary">{{ $change['name'] }}</h4>
                                <p class="mt-1 text-xs text-secondary">{{ $change['environment'] }} / {{ $change['kind'] }}</p>
                            </div>
                            <x-ui.badge>{{ ucfirst(str_replace('_', ' ', $change['action'])) }}</x-ui.badge>
                        </div>
                        @if ($change['kind'] === 'deployment')<p class="mt-3 text-xs text-secondary">{{ ($change['requires_approval'] ?? false) ? __('Approval required before deployment') : __('Queued after local apply; not immediate remote success') }}</p>@endif
                        @if ($change['action'] === 'detach')<p class="mt-3 text-xs text-secondary">{{ __('Remote data preserved') }}</p>@endif
                        <p class="mt-3 text-sm text-secondary"><span class="font-semibold text-primary">{{ __('Reviewed fields') }}:</span> {{ $change['fields'] === [] ? '—' : implode(', ', $change['fields']) }}</p>
                    </article>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-secondary">{{ __('Command and credential values are hidden. Fields identify the settings under review, not a plaintext diff.') }}</p>
            <p class="mt-4 text-sm text-secondary">{{ __('Expires') }}: {{ $review->expires_at->toIso8601String() }}</p>
            @if ($plan['apply_available'])
                <form method="POST" action="{{ route('projects.configuration.apply', ['project' => $project, 'review' => $review, 'dialog' => 'application-configuration']) }}" class="mt-4">
                    @csrf
                    <x-ui.button type="submit" variant="primary">{{ __('Apply reviewed configuration') }}</x-ui.button>
                </form>
            @else
                <p class="mt-4 text-sm text-secondary">{{ __('Explicit adoption is required. Submit an updated document for a new review.') }}</p>
            @endif
        </x-ui.card>
        <a href="{{ $applicationPageUrl }}" data-modal-trigger="{{ $applicationDialogId }}" data-modal-content-url="{{ $authoringContentUrl }}" aria-controls="{{ $applicationDialogId }}" aria-expanded="false" class="font-semibold text-ternary underline">{{ __('Edit configuration') }}</a>
    @else
        @if ($recentApplications->isNotEmpty())
            <details class="ui-card p-4">
                <summary class="cursor-pointer font-bold text-primary">{{ __('Recent application receipts') }}</summary>
                <ul class="mt-3 space-y-2">
                    @foreach ($recentApplications as $receipt)
                        <li>
                            <a href="{{ $applicationPageUrl.'&configuration_review='.$receipt->configuration_review_id }}" data-modal-trigger="{{ $applicationDialogId }}" data-modal-content-url="{{ route('projects.configuration.dialog', ['project' => $project, 'configuration_review' => $receipt->configuration_review_id]) }}" aria-controls="{{ $applicationDialogId }}" aria-expanded="false" class="text-sm text-ternary underline">{{ __('Application receipt') }} #{{ $receipt->id }} · {{ $receipt->status }}</a>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        <section class="ui-card p-4 sm:p-5" aria-labelledby="configuration-authoring-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 id="configuration-authoring-heading" class="font-bold text-primary">{{ __('Create a review') }}</h3>
                    <p class="mt-1 text-sm text-secondary">{{ __('Submit YAML and workspace bindings for an immutable review. Secret values are never shown or flashed back.') }}</p>
                </div>
                <x-ui.badge>{{ trans_choice(':count environment|:count environments', $environmentOverview->count(), ['count' => $environmentOverview->count()]) }}</x-ui.badge>
            </div>

            <form method="POST" action="{{ route('projects.configuration.store', ['project' => $project, 'dialog' => 'application-configuration']) }}" class="mt-5 space-y-5">
                @csrf
                <label class="block"><span class="mb-2 block text-sm font-semibold text-primary">{{ __('Version 2 YAML document') }}</span><textarea required name="document" rows="12" class="input secondary w-full rounded-lg font-mono" spellcheck="false"></textarea></label>
                <label class="block"><span class="mb-2 block text-sm font-semibold text-primary">{{ __('Workspace bindings (JSON)') }}</span><textarea required name="bindings" rows="5" class="input secondary w-full rounded-lg font-mono" spellcheck="false" placeholder='{"placements":{"site":1},"secrets":{},"repositories":{}}'></textarea></label>
                <p class="text-xs text-secondary">{{ __('Use existing website, secret-variable and repository IDs from this workspace. Do not paste secret values. Inputs are not retained after a validation error.') }}</p>
                <x-ui.button type="submit" variant="primary">{{ __('Create review') }}</x-ui.button>
            </form>
        </section>

        <details class="ui-card p-4">
            <summary class="cursor-pointer font-bold text-primary">{{ __('Authoring guide and binding IDs') }}</summary>
            <p class="mt-3 text-sm text-secondary">{{ __('Use the parser-valid examples below. The catalog shows identifiers only and never secret values.') }}</p>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <section>
                    <h3 class="font-semibold text-primary">{{ __('Starter YAML') }}</h3>
                    <pre class="mt-2 max-h-64 overflow-auto rounded-lg border border-primary bg-secondary p-3 text-xs text-primary" tabindex="0"><code>{{ $authoringGuide['document'] }}</code></pre>
                </section>
                <section>
                    <h3 class="font-semibold text-primary">{{ __('Starter bindings JSON') }}</h3>
                    <pre class="mt-2 max-h-64 overflow-auto rounded-lg border border-primary bg-secondary p-3 text-xs text-primary" tabindex="0"><code>{{ $authoringGuide['bindings'] }}</code></pre>
                </section>
            </div>
            <div class="mt-4 grid gap-4 text-sm lg:grid-cols-3">
                <section><h3 class="font-semibold text-primary">{{ __('Websites') }}</h3><ul class="mt-2 space-y-1">@forelse($websites->items() as $site)<li class="text-secondary">#{{ $site->id }} · {{ $site->name }}</li>@empty<li class="text-secondary">{{ __('None available.') }}</li>@endforelse</ul></section>
                <section><h3 class="font-semibold text-primary">{{ __('Secrets') }}</h3><ul class="mt-2 space-y-1">@forelse($secrets->items() as $secret)<li class="text-secondary">#{{ $secret->id }} · {{ $secret->key }} · {{ $secret->environment->name }}</li>@empty<li class="text-secondary">{{ __('None available.') }}</li>@endforelse</ul></section>
                <section><h3 class="font-semibold text-primary">{{ __('Repositories') }}</h3><ul class="mt-2 space-y-1">@forelse($repositories->items() as $repository)<li class="text-secondary">#{{ $repository->id }} · {{ $repository->name }}</li>@empty<li class="text-secondary">{{ __('None available.') }}</li>@endforelse</ul></section>
            </div>
            <a href="{{ $fullPageUrl }}" class="mt-4 inline-block text-sm font-semibold text-ternary underline">{{ __('Open the full binding catalog') }}</a>
        </details>
    @endif
</div>
