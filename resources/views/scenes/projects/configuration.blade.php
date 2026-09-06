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
