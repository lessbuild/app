<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\UpdateAlertRoutingRequest;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Services\ChangeAlertRouting;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;

class AlertRoutingController extends Controller
{
    public function update(UpdateAlertRoutingRequest $request, AlertRule $alertRule, CurrentWorkspace $workspace, ChangeAlertRouting $routing): RedirectResponse
    {
        $routing->update($workspace->get(), $request->user(), $alertRule, $request->validated());

        return to_route('monitor.alerts.show', $alertRule)->with('status', 'Routing saved for future incident transitions. Existing incidents are not backfilled.');
    }
}
