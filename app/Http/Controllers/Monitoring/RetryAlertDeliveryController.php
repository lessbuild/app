<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\RetryAlertDelivery;
use App\Models\AlertDelivery;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RetryAlertDeliveryController
{
    /**
     * Resends a delivery once the person confirms they understand it may arrive twice.
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AlertDelivery $delivery, RetryAlertDelivery $retry): RedirectResponse
    {
        $generation = (int) $request->validate(
            ['generation' => ['required', 'integer', 'min:0'], 'confirm' => ['accepted']],
            ['confirm.accepted' => __('Confirm that you checked the previous attempt and understand a retry may send a duplicate.')],
        )['generation'];
        $retry->handle($project->account, $user, $delivery, $generation);

        return to_route('monitoring.destinations.show', [$project, $delivery->alert_destination_id])->with('status', __('Delivery queued again.'));
    }
}
