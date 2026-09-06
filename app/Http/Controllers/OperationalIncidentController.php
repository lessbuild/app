<?php

namespace App\Http\Controllers;

use App\Models\OperationalIncident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalIncidentController extends Controller
{
    public function acknowledge(Request $request, OperationalIncident $incident): RedirectResponse
    {
        $this->authorizeIncident($request, $incident);
        abort_if($incident->status === OperationalIncident::STATUS_RESOLVED, 422, 'A resolved incident cannot be acknowledged.');
        $incident->update([
            'status' => OperationalIncident::STATUS_ACKNOWLEDGED,
            'assigned_to' => $incident->assigned_to ?: $request->user()->id,
            'acknowledged_at' => $incident->acknowledged_at ?: now(),
        ]);
        $incident->events()->create(['actor_id' => $request->user()->id, 'type' => 'acknowledged', 'message' => 'Incident acknowledged.', 'occurred_at' => now()]);

        return back()->with('success', __('Incident acknowledged.'));
    }

    public function assign(Request $request, OperationalIncident $incident): RedirectResponse
    {
        $this->authorizeIncident($request, $incident);
        $data = $request->validate(['assigned_to' => ['nullable', 'integer']]);
        if (filled($data['assigned_to'])) {
            $isResponder = (int) $incident->organization->owner_id === (int) $data['assigned_to']
                || $incident->organization->members()->whereKey($data['assigned_to'])->exists();
            abort_unless($isResponder, 422, 'The selected responder is not a member of this workspace.');
        }
        $incident->update(['assigned_to' => $data['assigned_to'] ?? null]);
        $name = $incident->fresh()->assignee?->name ?? 'Unassigned';
        $incident->events()->create(['actor_id' => $request->user()->id, 'type' => 'assigned', 'message' => 'Owner changed to '.$name.'.', 'occurred_at' => now()]);

        return back()->with('success', __('Incident owner updated.'));
    }

    public function note(Request $request, OperationalIncident $incident): RedirectResponse
    {
        $this->authorizeIncident($request, $incident);
        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        $incident->events()->create(['actor_id' => $request->user()->id, 'type' => 'note', 'message' => $data['message'], 'occurred_at' => now()]);

        return back()->with('success', __('Timeline note added.'));
    }

    public function resolve(Request $request, OperationalIncident $incident): RedirectResponse
    {
        $this->authorizeIncident($request, $incident);
        $data = $request->validate(['resolution' => ['required', 'string', 'max:5000']]);
        DB::transaction(function () use ($incident, $request, $data): void {
            $incident->update(['status' => OperationalIncident::STATUS_RESOLVED, 'active_key' => null, 'resolution' => $data['resolution'], 'resolved_at' => now()]);
            $incident->events()->create(['actor_id' => $request->user()->id, 'type' => 'resolved', 'message' => $data['resolution'], 'occurred_at' => now()]);
        });

        return back()->with('success', __('Incident resolved.'));
    }

    public function export(Request $request): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->permits($request->user(), 'audit') || $organization->permits($request->user(), 'operate'), 403);
        $incidents = $organization->operationalIncidents()->with('assignee')->latest('detected_at')->get();

        return response()->streamDownload(function () use ($incidents): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Status', 'Severity', 'Category', 'Resource ID', 'Title', 'Owner', 'Occurrences', 'Detected UTC', 'Resolved UTC', 'Summary', 'Resolution']);
            foreach ($incidents as $incident) {
                $safe = fn ($value): string => preg_match('/\A[=+\-@]/', (string) $value) ? "'".$value : (string) $value;
                fputcsv($out, array_map($safe, [$incident->id, $incident->status, $incident->severity, $incident->category, $incident->resource_id, $incident->title, $incident->assignee?->name, $incident->occurrences, $incident->detected_at?->utc()->toIso8601String(), $incident->resolved_at?->utc()->toIso8601String(), $incident->summary, $incident->resolution]));
            }
            fclose($out);
        }, 'operational-incidents.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    private function authorizeIncident(Request $request, OperationalIncident $incident): void
    {
        abort_unless($incident->organization_id === $request->user()->current_organization_id && $incident->organization->permits($request->user(), 'operate'), 403);
    }
}
