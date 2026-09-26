<?php

namespace App\Core\Services\Connections;

use App\Core\Data\Connections\ProjectConnectionDiagnostic;
use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectConnection;
use Carbon\CarbonInterface;
use Illuminate\Database\LostConnectionException;
use Illuminate\Support\Collection;
use PDOException;

final class ProjectConnectionDiagnostics
{
    public function __construct(private readonly ProjectConnectionDiagnosticRegistry $providers) {}

    /**
     * @param  Collection<int, ProjectConnection>  $connections
     * @return array<string, ProjectConnectionDiagnostic>
     */
    public function forConnections(Collection $connections, ?PlatformUser $user = null): array
    {
        return $connections
            ->mapWithKeys(fn (ProjectConnection $connection): array => [
                (string) $connection->getKey() => $this->forConnection($connection, $user),
            ])
            ->all();
    }

    public function forConnection(ProjectConnection $connection, ?PlatformUser $user = null): ProjectConnectionDiagnostic
    {
        $workflowDiagnostic = $this->workflowDiagnostic($connection);

        if ($user === null || $connection->status !== 'active' || $this->hasDeliveryIssue($connection)) {
            return $workflowDiagnostic;
        }

        $productDiagnostic = null;

        foreach (['targetResource', 'sourceResource'] as $relation) {
            if (! $connection->relationLoaded($relation)) {
                continue;
            }

            $resource = $connection->getRelation($relation);
            if ($resource === null) {
                continue;
            }

            $provider = $this->providers->get((string) $resource->product);
            if ($provider === null) {
                continue;
            }

            try {
                $diagnostic = $provider->diagnose($user, $connection, $resource);
            } catch (LostConnectionException|PDOException) {
                $diagnostic = new ProjectConnectionDiagnostic(
                    tone: 'warning',
                    status: __('App data unavailable'),
                    summary: __('The connected app could not provide current diagnostic data.'),
                    detail: __('The connection itself is still configured, but its app data could not be checked.'),
                    nextStep: __('Try again later. If this continues, check the connected app’s availability.'),
                    lastAttemptAt: null,
                    lastSucceededAt: $connection->last_succeeded_at,
                    priority: 25,
                );
            }

            if ($diagnostic !== null
                && ($productDiagnostic === null || $diagnostic->priority < $productDiagnostic->priority)) {
                $productDiagnostic = $diagnostic;
            }
        }

        return $productDiagnostic ?? $workflowDiagnostic;
    }

    private function workflowDiagnostic(ProjectConnection $connection): ProjectConnectionDiagnostic
    {
        $latestDelivery = $connection->relationLoaded('deliveries')
            ? $connection->deliveries->first()
            : null;
        $errorCode = $latestDelivery?->last_error_code ?? $connection->last_error_code;

        if ($errorCode !== null) {
            return $this->fromErrorCode(
                $errorCode,
                $latestDelivery?->last_attempted_at ?? $latestDelivery?->last_error_at ?? $connection->last_error_at,
                $connection->last_succeeded_at,
            );
        }

        if ($connection->automation_paused_at !== null) {
            return new ProjectConnectionDiagnostic(
                tone: 'warning',
                status: __('Paused'),
                summary: __('Workflow automation is paused.'),
                detail: __('The connection and delivery history remain in place. Queued updates wait until automation resumes.'),
                nextStep: __('Resume automation when you are ready to continue.'),
                lastAttemptAt: $latestDelivery?->last_attempted_at,
                lastSucceededAt: $connection->last_succeeded_at,
            );
        }

        if ($latestDelivery?->status === 'processing') {
            return new ProjectConnectionDiagnostic(
                tone: 'info',
                status: __('In progress'),
                summary: __('The receiving app is applying an update.'),
                detail: __('Delivery is in progress. The source event remains recorded if this app is temporarily unavailable.'),
                nextStep: null,
                lastAttemptAt: $latestDelivery->last_attempted_at,
                lastSucceededAt: $connection->last_succeeded_at,
            );
        }

        if ($latestDelivery?->status === 'pending') {
            return new ProjectConnectionDiagnostic(
                tone: 'warning',
                status: $latestDelivery->available_at?->isFuture() ? __('Retry scheduled') : __('Queued'),
                summary: __('A workflow update is waiting for delivery.'),
                detail: __('The update remains queued and will be retried automatically.'),
                nextStep: __('Check the receiving app if this continues.'),
                lastAttemptAt: $latestDelivery->last_attempted_at,
                lastSucceededAt: $connection->last_succeeded_at,
            );
        }

        if (in_array(
            ProjectConnectionCapability::TrafficContext->value,
            (array) $connection->capabilities,
            true,
        )) {
            return new ProjectConnectionDiagnostic(
                tone: 'success',
                status: __('Available on demand'),
                summary: __('Read-only traffic context is available for Monitor investigations.'),
                detail: __('This workflow reads plan-bounded Analytics aggregates when an authorized investigation requests them.'),
                nextStep: null,
                lastAttemptAt: null,
                lastSucceededAt: null,
            );
        }

        if (in_array($connection->status, ['pending', 'provisioning'], true)) {
            return new ProjectConnectionDiagnostic(
                tone: 'info',
                status: __('Waiting'),
                summary: __('Waiting for the first matching app event.'),
                detail: __('This connection is saved. It will run when a supported event occurs.'),
                nextStep: null,
                lastAttemptAt: null,
                lastSucceededAt: $connection->last_succeeded_at,
            );
        }

        if ($connection->last_succeeded_at !== null) {
            return new ProjectConnectionDiagnostic(
                tone: 'success',
                status: __('Healthy'),
                summary: __('The latest workflow update succeeded.'),
                detail: __('No delivery issue needs attention.'),
                nextStep: null,
                lastAttemptAt: $latestDelivery?->last_attempted_at,
                lastSucceededAt: $connection->last_succeeded_at,
            );
        }

        return new ProjectConnectionDiagnostic(
            tone: 'info',
            status: __('Ready'),
            summary: __('No matching app event has used this connection yet.'),
            detail: __('The workflow is ready and will run when a supported event occurs.'),
            nextStep: null,
            lastAttemptAt: $latestDelivery?->last_attempted_at,
            lastSucceededAt: null,
        );
    }

    private function hasDeliveryIssue(ProjectConnection $connection): bool
    {
        $latestDelivery = $connection->relationLoaded('deliveries')
            ? $connection->deliveries->first()
            : null;

        return $connection->last_error_code !== null
            || $latestDelivery?->last_error_code !== null
            || $connection->automation_paused_at !== null
            || in_array($latestDelivery?->status, ['pending', 'processing', 'blocked', 'failed'], true);
    }

    private function fromErrorCode(
        string $errorCode,
        ?CarbonInterface $lastAttemptAt,
        ?CarbonInterface $lastSucceededAt,
    ): ProjectConnectionDiagnostic {
        [$tone, $status, $summary, $detail, $nextStep] = match ($errorCode) {
            'target_product_disabled' => [
                'warning', __('App unavailable'), __('The receiving app is currently unavailable.'),
                __('The source update is preserved while the receiving app is unavailable.'),
                __('Ask a workspace admin to check the receiving app, then retry.'),
            ],
            'connection_authorization_failed' => [
                'warning', __('Access changed'), __('Access changed in one of the connected apps.'),
                __('The workflow stopped because current app access could not be confirmed.'),
                __('Restore workspace access at both ends, then retry the update.'),
            ],
            'product_access_changed' => [
                'warning', __('Access changed'), __('Workspace access to a connected app changed.'),
                __('The workflow stopped because one of the required app grants is no longer active.'),
                __('Restore the required workspace app access, then retry this delivery.'),
            ],
            'connection_unavailable', 'resource_mapping_changed' => [
                'warning', __('Mapping needs review'), __('A connected resource mapping changed.'),
                __('The workflow stopped because the current resource mapping could not be confirmed.'),
                __('Review both project resources and reconnect them before retrying.'),
            ],
            'project_unavailable', 'product_not_enabled' => [
                'warning', __('Project setup changed'), __('The project or an app connection is no longer active.'),
                __('The workflow stopped because its project context is unavailable.'),
                __('Restore the project and app connections before retrying.'),
            ],
            'product_subscription_unavailable' => [
                'warning', __('Subscription needs attention'), __('A connected app subscription could not be confirmed.'),
                __('The source event is preserved while the app plan is checked.'),
                __('Review the subscription for each connected app, then retry this delivery.'),
            ],
            'product_feature_not_included' => [
                'warning', __('Plan needs review'), __('A connected app plan does not include this workflow behavior.'),
                __('The source event remains recorded; no app data was changed by this blocked step.'),
                __('Review the plans for both connected apps before retrying.'),
            ],
            'product_limit_unavailable' => [
                'warning', __('Plan limit needs review'), __('The receiving app plan does not allow this context window.'),
                __('The source event remains recorded; this configured limit does not permit the requested workflow.'),
                __('Review the receiving app plan and its connection limits before retrying.'),
            ],
            'connection_disconnected' => [
                'warning', __('Reconnect required'), __('This connection is no longer active.'),
                __('Queued updates were stopped after the connection was disconnected.'),
                __('Reconnect the resources before sending new updates.'),
            ],
            'automation_paused' => [
                'warning', __('Automation paused'), __('This step stopped when automation was paused.'),
                __('The source event is still recorded. Resume automation before retrying this step.'),
                __('Resume automation, then retry this delivery step.'),
            ],
            'unsupported_event', 'unsupported_capability' => [
                'warning', __('Workflow needs review'), __('This workflow can no longer handle the event.'),
                __('The configured event or behavior is not supported by the current app pair.'),
                __('Choose a supported resource pair and behavior before reconnecting.'),
            ],
            'delivery_payload_conflict' => [
                'danger', __('Event conflict'), __('The receiving app found conflicting data for this event.'),
                __('Retrying the same event will not resolve the conflict.'),
                __('Review the source event and contact an app admin before retrying.'),
            ],
            'invalid_event_payload', 'invalid_event_timestamp' => [
                'danger', __('Event needs review'), __('The receiving app could not validate the source event.'),
                __('The event was stopped to avoid applying incomplete or invalid data.'),
                __('Review the source event with an app admin before retrying.'),
            ],
            'target_delivery_failed' => [
                'danger', __('Delivery failed'), __('The receiving app could not apply this update.'),
                __('The source event remains recorded; a temporary target problem may be retryable.'),
                __('Check the receiving app, then retry this delivery.'),
            ],
            default => [
                'warning', __('Needs attention'), __('A workflow update could not be completed.'),
                __('The source event remains recorded. Sensitive provider details are hidden.'),
                __('Check both apps and access, then retry if the issue continues.'),
            ],
        };

        return new ProjectConnectionDiagnostic(
            tone: $tone,
            status: $status,
            summary: $summary,
            detail: $detail,
            nextStep: $nextStep,
            lastAttemptAt: $lastAttemptAt,
            lastSucceededAt: $lastSucceededAt,
        );
    }
}
