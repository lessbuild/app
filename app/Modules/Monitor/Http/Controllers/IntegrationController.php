<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\IntegrationSetupRequest;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\IntegrationSetupGuide;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(IntegrationSetupRequest $request, CurrentWorkspace $currentWorkspace, IntegrationSetupGuide $guide): View
    {
        $workspace = $currentWorkspace->get();
        $selectedStack = $request->stack();

        return view('monitor::settings.integrations', [
            'selectedStack' => $selectedStack,
            'stackOptions' => $guide->stacks(),
            'setupGuide' => $guide->for($selectedStack, route('monitor.api.ingest'), route('monitor.api.ingest.receipts.show', 'RECEIPT_ID')),
            'otlpConfiguration' => $guide->openTelemetryConfiguration(
                route('monitor.api.otlp', 'traces'),
                route('monitor.api.otlp', 'logs'),
                route('monitor.api.otlp', 'metrics'),
            ),
            'applications' => $workspace->applications()->visibleTo(request()->user(), $workspace)->with(['environments' => fn ($query) => $query->visibleTo(request()->user(), $workspace)->orderBy('name')])
                ->orderBy('name')->orderBy('id')->paginate(12),
        ]);
    }
}
