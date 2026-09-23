<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\UpdateAlertEscalationsRequest;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Services\ChangeAlertEscalations;
use App\Modules\Monitor\Services\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;

class AlertEscalationController extends Controller
{
    public function update(UpdateAlertEscalationsRequest $request, AlertRule $alertRule, CurrentWorkspace $workspace, ChangeAlertEscalations $escalations): RedirectResponse
    {
        $escalations->update($workspace->get(), $request->user(), $alertRule, $request->validated());

        return to_route('monitor.alerts.show', $alertRule)->with('status', 'Escalation policy saved. New incidents will notify each step after its delay.');
    }
}
