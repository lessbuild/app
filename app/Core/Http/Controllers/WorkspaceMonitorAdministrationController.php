<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\SaveWorkspaceMonitorAlertRuleRequest;
use App\Core\Http\Requests\SaveWorkspaceMonitorDestinationRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceMonitorAdministrationRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WorkspaceMonitorAdministrationController
{
    public function alerts(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): Response
    {
        [$user, $workspaces] = $this->coreContext($request, $workspace, $access);
        $provider = $providers->alerts();
        abort_if($provider === null, 404);
        $filters = $request->validate([
            'rules_search' => ['nullable', 'string', 'max:100'], 'rules_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'environment_search' => ['nullable', 'string', 'max:100'], 'environment_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'destination_search' => ['nullable', 'string', 'max:100'], 'destination_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'series_search' => ['nullable', 'string', 'max:100'], 'series_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'objective_search' => ['nullable', 'string', 'max:100'], 'objective_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $snapshot = $provider->snapshot($user, $workspace, array_filter($filters, fn ($value) => $value !== null && $value !== ''));
        abort_unless($snapshot->available, 404);

        return response()->view('core::workspaces.monitor.alerts', $this->viewContext($user, $workspace, $workspaces) + [
            'items' => $snapshot->items, 'settings' => $snapshot->settings,
        ])->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function saveRule(SaveWorkspaceMonitorAlertRuleRequest $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        [$user] = $this->coreContext($request, $workspace, $access);
        $data = $request->validated();
        $ruleReference = $data['rule_reference'] ?? null;
        unset($data['rule_reference']);
        abort_unless($ruleReference === null || filled($data['version'] ?? null), 422);
        $provider = $providers->alerts();
        abort_if($provider === null, 404);
        try {
            $result = $provider->saveRule($user, $workspace, $ruleReference, $data);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput($request->except(['service', 'match_text']));
        }

        return to_route('core.workspace.monitor.alerts', $workspace)->with('status', $result->status);
    }

    public function archiveRule(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        [$user] = $this->coreContext($request, $workspace, $access);
        $data = $request->validate(['rule_reference' => ['required', 'string', 'max:4096'], 'version' => ['required', 'integer', 'min:0']]);
        $provider = $providers->alerts();
        abort_if($provider === null, 404);
        $result = $provider->archiveRule($user, $workspace, $data['rule_reference'], (int) $data['version']);

        return to_route('core.workspace.monitor.alerts', $workspace)->with('status', $result->status);
    }

    public function routing(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        [$user] = $this->coreContext($request, $workspace, $access);
        $data = $request->validate([
            'rule_reference' => ['required', 'string', 'max:4096'], 'version' => ['required', 'integer', 'min:0'], 'destinations' => ['sometimes', 'array', 'max:5'],
            'destinations.*' => ['required', 'string', 'max:4096', 'distinct'],
            'opened' => ['required', 'boolean'], 'recovered' => ['required', 'boolean'],
        ]);
        $provider = $providers->alerts();
        abort_if($provider === null, 404);
        $result = $provider->saveRouting($user, $workspace, $data['rule_reference'], $data);

        return to_route('core.workspace.monitor.alerts', $workspace)->with('status', $result->status);
    }

    public function escalations(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        [$user] = $this->coreContext($request, $workspace, $access);
        $data = $request->validate([
            'rule_reference' => ['required', 'string', 'max:4096'], 'version' => ['required', 'integer', 'min:0'], 'escalations' => ['sometimes', 'array', 'max:10'],
            'escalations.*.destination_reference' => ['nullable', 'string', 'max:4096'],
            'escalations.*.delay_minutes' => ['nullable', 'integer', 'between:1,10080'],
        ]);
        $provider = $providers->alerts();
        abort_if($provider === null, 404);
        $result = $provider->saveEscalations($user, $workspace, $data['rule_reference'], $data);

        return to_route('core.workspace.monitor.alerts', $workspace)->with('status', $result->status);
    }

    public function destinations(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): Response
    {
        [$user, $workspaces] = $this->coreContext($request, $workspace, $access);
        $provider = $providers->destinations();
        abort_if($provider === null, 404);
        $filters = $request->validate([
            'destination_search' => ['nullable', 'string', 'max:100'], 'destination_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'recipient_search' => ['nullable', 'string', 'max:100'], 'recipient_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'delivery_destination' => ['nullable', 'string', 'max:4096'], 'delivery_search' => ['nullable', 'string', 'max:100'],
            'delivery_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $snapshot = $provider->snapshot($user, $workspace, array_filter($filters, fn ($value) => $value !== null && $value !== ''));
        abort_unless($snapshot->available, 404);

        return response()->view('core::workspaces.monitor.destinations', $this->viewContext($user, $workspace, $workspaces) + [
            'items' => $snapshot->items, 'settings' => $snapshot->settings,
        ])->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function saveDestination(SaveWorkspaceMonitorDestinationRequest $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse|Response
    {
        [$user, $workspaces] = $this->coreContext($request, $workspace, $access);
        $provider = $providers->destinations();
        abort_if($provider === null, 404);
        $data = $request->validated();
        $destinationReference = $data['destination_reference'] ?? null;
        abort_unless($destinationReference === null || filled($data['version'] ?? null), 422);
        try {
            $result = $provider->saveDestination($user, $workspace, $destinationReference, $data);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput($request->except(['endpoint_url', 'signing_secret']));
        }

        if (($secret = $result->takeOneTimeSecret()) !== null) {
            return response()->view('core::workspaces.monitor.secret-issued', $this->viewContext($user, $workspace, $workspaces) + [
                'secret' => $secret, 'status' => $result->status,
            ])->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
        }

        return to_route('core.workspace.monitor.destinations', $workspace)->with('status', $result->status);
    }

    public function changeDestination(Request $request, Workspace $workspace, string $action, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse|Response
    {
        abort_unless(in_array($action, ['archive', 'rotate', 'test'], true), 404);
        [$user, $workspaces] = $this->coreContext($request, $workspace, $access);
        $data = $request->validate(['destination_reference' => ['required', 'string', 'max:4096'], 'version' => ['required', 'integer', 'min:0']]);
        $provider = $providers->destinations();
        abort_if($provider === null, 404);
        $result = match ($action) {
            'archive' => $provider->archiveDestination($user, $workspace, $data['destination_reference'], (int) $data['version']),
            'rotate' => $provider->rotateDestination($user, $workspace, $data['destination_reference'], (int) $data['version']),
            'test' => $provider->testDestination($user, $workspace, $data['destination_reference'], (int) $data['version']),
        };
        if (($secret = $result->takeOneTimeSecret()) !== null) {
            return response()->view('core::workspaces.monitor.secret-issued', $this->viewContext($user, $workspace, $workspaces) + [
                'secret' => $secret, 'status' => $result->status,
            ])->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
        }

        return to_route('core.workspace.monitor.destinations', $workspace)->with('status', $result->status);
    }

    public function retryDelivery(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        [$user] = $this->coreContext($request, $workspace, $access);
        $data = $request->validate(['delivery_reference' => ['required', 'string', 'max:4096'], 'generation' => ['required', 'integer', 'min:0'], 'confirm' => ['accepted']]);
        $provider = $providers->destinations();
        abort_if($provider === null, 404);
        $result = $provider->retryDelivery($user, $workspace, $data['delivery_reference'], (int) $data['generation']);

        return to_route('core.workspace.monitor.destinations', $workspace)->with('status', $result->status);
    }

    public function settings(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): Response
    {
        [$user, $workspaces] = $this->coreContext($request, $workspace, $access);
        $provider = $providers->settings();
        abort_if($provider === null, 404);
        $snapshot = $provider->snapshot($user, $workspace);
        abort_unless($snapshot->available, 404);

        return response()->view('core::workspaces.monitor.settings', $this->viewContext($user, $workspace, $workspaces) + [
            'items' => $snapshot->items, 'settings' => $snapshot->settings,
        ])->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function saveSettings(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): RedirectResponse
    {
        [$user] = $this->coreContext($request, $workspace, $access);
        $data = $request->validate(['digest_enabled' => ['sometimes', 'boolean']]);
        $provider = $providers->settings();
        abort_if($provider === null, 404);
        $result = $provider->saveNotificationPreferences($user, $workspace, $data);

        return to_route('core.workspace.monitor.settings', $workspace)->with('status', $result->status);
    }

    public function integrations(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): Response
    {
        [$user, $workspaces] = $this->coreContext($request, $workspace, $access);
        $provider = $providers->settings();
        abort_if($provider === null, 404);
        $snapshot = $provider->integrationGuide($user, $workspace);
        abort_unless($snapshot->available, 404);

        return response()->view('core::workspaces.monitor.integrations', $this->viewContext($user, $workspace, $workspaces) + ['items' => $snapshot->items])
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function audit(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): Response
    {
        [$user, $workspaces] = $this->coreContext($request, $workspace, $access);
        $provider = $providers->settings();
        abort_if($provider === null, 404);
        $filters = $request->validate([
            'audit_search' => ['nullable', 'string', 'max:100'], 'audit_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $snapshot = $provider->auditSnapshot($user, $workspace, array_filter($filters, fn ($value) => $value !== null && $value !== ''));
        abort_unless($snapshot->available, 404);

        return response()->view('core::workspaces.monitor.audit', $this->viewContext($user, $workspace, $workspaces) + [
            'items' => $snapshot->items, 'settings' => $snapshot->settings,
        ])
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function export(Request $request, Workspace $workspace, WorkspaceProjectAccess $access, WorkspaceMonitorAdministrationRegistry $providers): StreamedResponse
    {
        [$user] = $this->coreContext($request, $workspace, $access);
        $provider = $providers->settings();
        abort_if($provider === null, 404);

        return response()->streamDownload(function () use ($provider, $user, $workspace): void {
            $output = fopen('php://output', 'wb');
            abort_if($output === false, 500);
            try {
                $provider->writeExport($user, $workspace, $output);
            } finally {
                fclose($output);
            }
        }, 'monitor-'.$workspace->slug.'-export-'.now('UTC')->format('Ymd-His').'.ndjson', [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8', 'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array{PlatformUser, Collection<int, Workspace>} */
    private function coreContext(Request $request, Workspace $workspace, WorkspaceProjectAccess $access): array
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);
        abort_if($access->activeMembership($user, $workspace) === null, 404);
        $workspaces = Workspace::query()->where('status', 'active')->whereNull('archived_at')
            ->whereHas('memberships', fn ($query) => $query->currentlyActive()->where('user_id', $user->getKey()))
            ->orderBy('name')->get();

        return [$user, $workspaces];
    }

    /** @param Collection<int, Workspace> $workspaces
     * @return array<string, mixed>
     */
    private function viewContext(PlatformUser $user, Workspace $workspace, $workspaces): array
    {
        return [
            'user' => $user, 'workspace' => $workspace, 'workspaces' => $workspaces,
            'contextProjects' => Project::query()->where('workspace_id', $workspace->getKey())
                ->where('status', 'active')->whereNull('archived_at')
                ->whereHas('memberships', fn ($membership) => $membership->where('user_id', $user->getKey())->where('status', 'active')->whereNull('revoked_at'))
                ->orderBy('name')->get()->map(fn (Project $project): array => [
                    'id' => (string) $project->getKey(), 'name' => $project->name,
                    'href' => route('core.projects.show', [$workspace, $project]),
                ]),
        ];
    }
}
