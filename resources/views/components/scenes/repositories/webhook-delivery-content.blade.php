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
            <p class="ui-eyebrow">{{ __('Webhook delivery') }}</p>
            <h3 class="mt-2 break-all font-mono text-xl font-extrabold text-ink">{{ $delivery->delivery_id }}</h3>
            <p class="mt-1 text-sm text-muted">{{ __('Review the accepted delivery record without exposing the source-control payload or webhook secret.') }}</p>
        </div>
        <x-ui.badge :tone="$statusTone">{{ str($delivery->status)->replace('_', ' ') }}</x-ui.badge>
    </div>

    <aside class="ui-panel border-l-4 border-line bg-surface-muted p-4 text-sm text-ink" style="border-left-color: var(--ui-primary)" role="status">{{ $outcome }}</aside>

    <dl class="grid gap-4 text-sm sm:grid-cols-2">
        <div>
            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Revision') }}</dt>
            <dd class="mt-1 break-all font-mono text-ink">
                @if ($delivery->revision)
                    @if ($revisionUrl = $repository->revisionUrl($delivery->revision))
                        <a href="{{ $revisionUrl }}" target="_blank" rel="noopener noreferrer" class="ui-link font-medium">{{ str($delivery->revision)->take(12) }}</a>
                    @else
                        {{ str($delivery->revision)->take(12) }}
                    @endif
                @else
                    &mdash;
                @endif
            </dd>
        </div>
        <div>
            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Received') }}</dt>
            <dd class="mt-1 text-ink">{{ $delivery->created_at?->toIso8601String() ?? __('Date unavailable') }}</dd>
        </div>
        <div>
            <dt class="ui-eyebrow text-[0.65rem]">{{ __('Updated') }}</dt>
            <dd class="mt-1 text-ink">{{ $delivery->updated_at?->toIso8601String() ?? __('Date unavailable') }}</dd>
        </div>
        @if ($delivery->build)
            <div>
                <dt class="ui-eyebrow text-[0.65rem]">{{ __('Deployment result') }}</dt>
                <dd class="mt-1"><a href="{{ route('builds.show', $delivery->build) }}" class="ui-link font-medium">{{ __('Build #:id · :status', ['id' => $delivery->build->id, 'status' => str($delivery->build->status)->replace('_', ' ')]) }}</a></dd>
            </div>
        @endif
    </dl>

    @if ($delivery->commit_message)
        <section class="rounded-card border border-line bg-surface-muted p-4" aria-labelledby="webhook-delivery-commit-heading">
            <h4 id="webhook-delivery-commit-heading" class="ui-eyebrow">{{ __('Commit message') }}</h4>
            <p class="mt-2 whitespace-pre-wrap break-words text-sm text-ink">{{ $delivery->commit_message }}</p>
        </section>
    @endif

    @if (is_array($delivery->changed_paths) && $delivery->changed_paths !== [])
        <section aria-labelledby="webhook-delivery-paths-heading">
            <h4 id="webhook-delivery-paths-heading" class="ui-eyebrow">{{ __('Changed paths') }}</h4>
            <ul class="mt-2 max-h-48 space-y-1 overflow-auto rounded-card border border-line bg-surface-muted p-3 font-mono text-xs text-ink">
                @foreach ($delivery->changed_paths as $path)
                    <li class="break-all">{{ $path }}</li>
                @endforeach
            </ul>
        </section>
    @else
        <p class="rounded-card border border-line border-l-4 p-4 text-sm text-muted">{{ __('Changed paths were not retained for this delivery.') }}</p>
    @endif
</div>
