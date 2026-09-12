<?php

namespace App\Http\Controllers;

use App\Actions\Observability\AcknowledgeOperationalIncidentAction;
use App\Actions\Observability\AddOperationalIncidentNoteAction;
use App\Actions\Observability\AssignOperationalIncidentAction;
use App\Actions\Observability\ResolveOperationalIncidentAction;
use App\Exceptions\OperationalIncidentOperationException;
use App\Http\Requests\AssignOperationalIncidentRequest;
use App\Http\Requests\ResolveOperationalIncidentRequest;
use App\Http\Requests\StoreOperationalIncidentNoteRequest;
use App\Models\OperationalIncident;
use App\Services\OperationalIncidentExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalIncidentController extends Controller
{
    /**
     * Authorize an unresolved workspace incident, record its acknowledgement and responder, then redirect back.
     */
    public function acknowledge(Request $request, OperationalIncident $incident, AcknowledgeOperationalIncidentAction $acknowledge): RedirectResponse
    {
        $this->authorize('acknowledge', $incident);
        try {
            $acknowledge->handle($incident, $request->user());
        } catch (OperationalIncidentOperationException $exception) {
            abort(422, $exception->getMessage());
        }

        return back()->with('success', __('Incident acknowledged.'));
    }

    /**
     * Validate a nullable workspace responder ID, record the incident ownership change, and redirect back.
     */
    public function assign(AssignOperationalIncidentRequest $request, OperationalIncident $incident, AssignOperationalIncidentAction $assign): RedirectResponse
    {
        try {
            $assign->handle($incident, $request->user(), $request->assignedTo());
        } catch (OperationalIncidentOperationException $exception) {
            abort(422, $exception->getMessage());
        }

        return back()->with('success', __('Incident owner updated.'));
    }

    /**
     * Validate a timeline message for an authorized incident, append the attributed note, and redirect back.
     */
    public function note(StoreOperationalIncidentNoteRequest $request, OperationalIncident $incident, AddOperationalIncidentNoteAction $addNote): RedirectResponse
    {
        $addNote->handle($incident, $request->user(), $request->message());

        return back()->with('success', __('Timeline note added.'));
    }

    /**
     * Validate a resolution for an authorized incident and atomically record resolved state and its timeline event.
     */
    public function resolve(ResolveOperationalIncidentRequest $request, OperationalIncident $incident, ResolveOperationalIncidentAction $resolve): RedirectResponse
    {
        $resolve->handle($incident, $request->user(), $request->resolution());

        return back()->with('success', __('Incident resolved.'));
    }

    /**
     * Require audit or operations access and stream current-workspace incidents as private, spreadsheet-safe CSV.
     */
    public function export(Request $request, OperationalIncidentExporter $exporter): StreamedResponse
    {
        $this->authorize('export', OperationalIncident::class);

        return $exporter->stream($request->user()->currentOrganization);
    }
}
