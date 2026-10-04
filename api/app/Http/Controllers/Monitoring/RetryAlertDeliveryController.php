<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\RetryAlertDelivery;
use App\Models\AlertDelivery;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RetryAlertDeliveryController
{
    /**
     * Resend a delivery once the person confirms they understand it may arrive twice.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AlertDelivery  $delivery
     * @param  RetryAlertDelivery  $retry
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AlertDelivery $delivery, RetryAlertDelivery $retry): JsonResponse
    {
        $generation = (int) $request->validate(
            ['generation' => ['required', 'integer', 'min:0'], 'confirm' => ['accepted']],
            ['confirm.accepted' => __('Confirm that you checked the previous attempt and understand a retry may send a duplicate.')],
        )['generation'];
        $retry->handle($project->account, $user, $delivery, $generation);

        return response()->json(['redirect' => route('monitoring.destinations.show', [$project, $delivery->alert_destination_id], false), 'message' => __('Delivery queued again.')]);
    }
}
