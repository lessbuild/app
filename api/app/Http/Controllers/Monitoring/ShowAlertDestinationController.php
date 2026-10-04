<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\AlertDestinationOptions;
use App\Enums\AlertDestinationType;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\AlertDestinationsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Monitoring\TwilioAlerts;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowAlertDestinationController
{
    /**
     * Show an alert destination: its settings (never its secrets) and its recent deliveries.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AlertDestination  $destination
     * @param  ProjectOverviewQuery  $overview
     * @param  AlertDestinationsQuery  $destinations
     * @param  TwilioAlerts  $twilio
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AlertDestination $destination, ProjectOverviewQuery $overview, AlertDestinationsQuery $destinations, TwilioAlerts $twilio): JsonResponse
    {
        $canManage = $user->can('update', $destination);
        $type = $destination->type;

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'destination' => [
                'id' => $destination->id,
                'name' => $destination->name,
                'type' => $type->value,
                'typeLabel' => $type->label(),
                'target' => $destination->targetLabel(),
                'enabled' => (bool) $destination->enabled,
                'version' => $destination->state_version,
                'archived' => $destination->trashed(),
                'recipient' => $destination->on_call_schedule_id !== null ? 'schedule:'.$destination->on_call_schedule_id : ($destination->recipient_user_id === null ? null : (string) $destination->recipient_user_id),
                'followsPerson' => $type->followsPerson(),
                'isPhone' => $type->isPhone(),
                'usesUrl' => in_array($type, [AlertDestinationType::Webhook, AlertDestinationType::Slack, AlertDestinationType::Teams, AlertDestinationType::Discord], true),
                'isWebhook' => $type === AlertDestinationType::Webhook,
                'isPagerDuty' => $type === AlertDestinationType::PagerDuty,
            ],
            'deliveries' => $destination->deliveries()->latest('created_at')->limit(50)->get()->map(fn (AlertDelivery $delivery): array => [
                'id' => $delivery->id,
                'event' => $delivery->event,
                'incidentId' => $delivery->incident_id,
                'status' => __($delivery->status->label()),
                'errorCode' => $delivery->last_error_code,
                'attempts' => $delivery->attempt_count,
                'retryable' => $delivery->status->retryable(),
                'generation' => $delivery->generation,
                'createdAt' => $delivery->created_at?->toIso8601String(),
            ])->values(),
            'options' => $canManage ? AlertDestinationOptions::for($destinations, $twilio, $project->account_id) : null,
            'canManage' => $canManage,
        ]);
    }
}
