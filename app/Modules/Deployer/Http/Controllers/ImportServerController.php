<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Actions\Server\ConfirmServerImportAction;
use App\Modules\Deployer\Actions\Server\InspectServerImportAction;
use App\Modules\Deployer\Http\Requests\ConfirmServerImportRequest;
use App\Modules\Deployer\Http\Requests\ImportServerRequest;
use App\Modules\Deployer\Models\Enums\Server\ServerTypeEnum;
use App\Modules\Deployer\Models\ServerImportAssessment;
use App\Modules\Deployer\Services\PlanLimits;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImportServerController extends Controller
{
    /**
     * Show supported server roles and the requesting workspace's current server allowance.
     */
    public function create(Request $request, PlanLimits $limits): View
    {
        return view('scenes.servers.import', [
            'types' => ServerTypeEnum::cases(),
            'planUsage' => $limits->usage($request->user(), 'servers'),
        ]);
    }

    /**
     * Inspect validated SSH connection details and redirect to a session-bound import assessment.
     *
     * Connection errors and exhausted server allowances become validation errors.
     */
    public function store(
        ImportServerRequest $request,
        InspectServerImportAction $inspect,
    ): RedirectResponse {
        $result = $inspect->handle($request->user(), $request->validated());
        $request->session()->put("server_import_assessment.{$result->assessment->id}", $result->token);

        return redirect()->route('servers.import.review', $result->assessment);
    }

    /**
     * Render an unexpired, unused import assessment authorized by its user and session token.
     */
    public function review(Request $request, ServerImportAssessment $assessment): View
    {
        $this->authorizeAssessment($request, $assessment);

        return view('scenes.servers.import-review', ['assessment' => $assessment]);
    }

    /**
     * Validate the server-name, backup, and host-fingerprint confirmations before consuming an assessment.
     *
     * @return RedirectResponse The imported server page after provisioning is queued following commit.
     */
    public function confirm(
        ConfirmServerImportRequest $request,
        ServerImportAssessment $assessment,
        ConfirmServerImportAction $confirm,
    ): RedirectResponse {
        $server = $confirm->handle($request->user(), $assessment, $request->token());
        $request->session()->forget("server_import_assessment.{$assessment->id}");

        return redirect()->route('servers.show', $server)
            ->with('success', __('Server imported. :app is securely connecting and applying the selected runtime.', ['app' => config('app.name')]));
    }

    /**
     * Require a usable assessment belonging to the request user and session token; otherwise return 404.
     */
    private function authorizeAssessment(Request $request, ServerImportAssessment $assessment): void
    {
        $token = (string) $request->session()->get("server_import_assessment.{$assessment->id}");
        abort_unless($assessment->isUsableBy($request->user(), $token), 404);
    }
}
