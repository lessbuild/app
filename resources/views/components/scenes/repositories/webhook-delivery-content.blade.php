@php
    $statusTone = match ($delivery->status) {
        \App\Models\RepositoryWebhookDelivery::STATUS_QUEUED => 'success',
        \App\Models\RepositoryWebhookDelivery::STATUS_PENDING => 'warning',
        \App\Models\RepositoryWebhookDelivery::STATUS_UNAVAILABLE => 'danger',
        \App\Models\RepositoryWebhookDelivery::STATUS_SKIPPED => 'accent',
        default => 'neutral',
    };
    $outcome = match ($delivery->status) {
        \App\Models\RepositoryWebhookDelivery::STATUS_QUEUED => __('A deployment was queued for this push.'),
        \App\Models\RepositoryWebhookDelivery::STATUS_PENDING => __('The push is waiting for the active deployment to finish.'),
        \App\Models\RepositoryWebhookDelivery::STATUS_UNAVAILABLE => __('The deployment target was unavailable when this push was accepted.'),
        \App\Models\RepositoryWebhookDelivery::STATUS_SUPERSEDED => __('A newer push replaced this delivery before it could deploy.'),
        \App\Models\RepositoryWebhookDelivery::STATUS_SKIPPED => __('The configured deployment paths were not affected by this push.'),
        default => __('The delivery was accepted and associated with a deployment.'),
    };
@endphp

<div data-webhook-delivery-content class="space-y-5 p-4 sm:p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-widest text-ternary">{{ __('Webhook delivery') }}</p>
            <h3 class="mt-1 break-all font-mono text-xl font-black text-primary">{{ $delivery->delivery_id }}</h3>
            <p class="mt-1 text-sm text-secondary">{{ __('Review the accepted delivery record without exposing the source-control payload or webhook secret.') }}</p>
        </div>
        <x-ui.badge :tone="$statusTone">{{ str($delivery->status)->replace('_', ' ') }}</x-ui.badge>
    </div>

    <x-ui.alert tone="info">{{ $outcome }}</x-ui.alert>

    <dl class="grid gap-4 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Revision') }}</dt>
            <dd class="mt-1 break-all font-mono text-primary">
                @if ($delivery->revision)
                    @if ($revisionUrl = $repository->revisionUrl($delivery->revision))
                        <a href="{{ $revisionUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-ternary underline">{{ str($delivery->revision)->take(12) }}</a>
                    @else
                        {{ str($delivery->revision)->take(12) }}
                    @endif
                @else
                    &mdash;
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Received') }}</dt>
            <dd class="mt-1 text-primary">{{ $delivery->created_at?->toIso8601String() ?? __('Date unavailable') }}</dd>
        </div>
        <div>
            <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Updated') }}</dt>
            <dd class="mt-1 text-primary">{{ $delivery->updated_at?->toIso8601String() ?? __('Date unavailable') }}</dd>
        </div>
        @if ($delivery->build)
            <div>
                <dt class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Deployment result') }}</dt>
                <dd class="mt-1"><a href="{{ route('builds.show', $delivery->build) }}" class="font-medium text-ternary underline">{{ __('Build #:id · :status', ['id' => $delivery->build->id, 'status' => str($delivery->build->status)->replace('_', ' ')]) }}</a></dd>
            </div>
        @endif
    </dl>

    @if ($delivery->commit_message)
        <section class="rounded-xl border border-primary bg-secondary p-4" aria-labelledby="webhook-delivery-commit-heading">
            <h4 id="webhook-delivery-commit-heading" class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Commit message') }}</h4>
            <p class="mt-2 whitespace-pre-wrap break-words text-sm text-primary">{{ $delivery->commit_message }}</p>
        </section>
    @endif

    @if (is_array($delivery->changed_paths) && $delivery->changed_paths !== [])
        <section aria-labelledby="webhook-delivery-paths-heading">
            <h4 id="webhook-delivery-paths-heading" class="text-xs font-bold uppercase tracking-wide text-secondary">{{ __('Changed paths') }}</h4>
            <ul class="mt-2 max-h-48 space-y-1 overflow-auto rounded-xl border border-primary bg-secondary p-3 font-mono text-xs text-primary">
                @foreach ($delivery->changed_paths as $path)
                    <li class="break-all">{{ $path }}</li>
                @endforeach
            </ul>
        </section>
    @else
        <p class="rounded-xl border border-primary p-4 text-sm text-secondary">{{ __('Changed paths were not retained for this delivery.') }}</p>
    @endif
</div>
