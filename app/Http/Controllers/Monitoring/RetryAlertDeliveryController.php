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
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $delivery, RetryAlertDelivery $retry): RedirectResponse
    {
        $generation = (int) $request->validate(
            ['generation' => ['required', 'integer', 'min:0'], 'confirm' => ['accepted']],
            ['confirm.accepted' => __('Confirm that you checked the previous attempt and understand a retry may send a duplicate.')],
        )['generation'];
        $target = AlertDelivery::query()->where('account_id', $project->account_id)->findOrFail($delivery);
        $retry->handle($project->account, $user, $target, $generation);

        return to_route('monitoring.destinations.show', [$project, $target->alert_destination_id])->with('status', __('Delivery queued again.'));
    }
}
