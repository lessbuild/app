<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\ChangeAlertDestinationRequest;
use App\Modules\Monitor\Http\Requests\RetryAlertDeliveryRequest;
use App\Modules\Monitor\Http\Requests\SearchAlertDeliveriesRequest;
use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\DeliverAlertNotification;
use App\Modules\Monitor\Services\RecordAlertDeliveries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class AlertDeliveryController extends Controller
{
    public function store(ChangeAlertDestinationRequest $request, AlertDestination $alertDestination, CurrentWorkspace $workspace, RecordAlertDeliveries $deliveries): RedirectResponse
    {
        $delivery = $deliveries->test($workspace->get(), $request->user(), $alertDestination, (int) $request->validated('version'));

        return to_route('monitor.alert-deliveries.show', $delivery)->with('status', 'Test notification queued. This is not confirmation of delivery.');
    }

    public function show(SearchAlertDeliveriesRequest $request, AlertDelivery $alertDelivery): Response
    {
        $alertDelivery->load('destination');
        $attempts = $alertDelivery->attempts()->orderByDesc('number')
            ->paginate(20, ['*'], 'page', (int) ($request->validated('page') ?? 1));

        return response()->view('monitor::alerts.delivery-show', ['delivery' => $alertDelivery, 'attempts' => $attempts])->header('Cache-Control', 'private, no-store');
    }

    public function update(RetryAlertDeliveryRequest $request, AlertDelivery $alertDelivery, CurrentWorkspace $workspace, DeliverAlertNotification $deliveries): RedirectResponse
    {
        $deliveries->retry($workspace->get(), $request->user(), $alertDelivery, (int) $request->validated('generation'));

        return to_route('monitor.alert-deliveries.show', $alertDelivery)->with('status', 'Retry queued using the current destination configuration.');
    }
}
