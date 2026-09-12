<?php

namespace App\Http\Controllers;

use App\Actions\Observability\CreateAlertDestinationAction;
use App\Actions\Observability\CreateMetricAlertRuleAction;
use App\Actions\Observability\CreateStatusPageAction;
use App\Actions\Observability\DeleteAlertDestinationAction;
use App\Actions\Observability\DeleteMetricAlertRuleAction;
use App\Actions\Observability\DeleteStatusPageAction;
use App\Actions\Observability\QueueAlertDestinationTestAction;
use App\Actions\Observability\UpdateStatusPageAction;
use App\Http\Requests\StoreAlertDestinationRequest;
use App\Http\Requests\StoreMetricAlertRuleRequest;
use App\Http\Requests\StoreStatusPageRequest;
use App\Http\Requests\UpdateStatusPageRequest;
use App\Models\AlertDestination;
use App\Models\Build;
use App\Models\MetricAlertRule;
use App\Models\StatusIncident;
use App\Models\StatusPage;
use App\Models\WebsiteHealthCheck;
use App\Services\Entitlements;
use App\Services\StatusSubscriberNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ObservabilityController extends Controller
{
    /**
     * Use workspace entitlements to gate alerting and public status-page configuration.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Render current-workspace alerts, bounded incident/deployment/health context, metrics, and responder permissions.
     */
    public function index(Request $request): View
    {
        $organization = $request->user()->currentOrganization;
        $incidents = StatusIncident::query()->whereHas('statusPage', fn ($query) => $query->where('organization_id', $organization->id))->with('statusPage')->latest('starts_at')->limit(50)->get();

        return view('observability.index', [
            'destinations' => $organization->alertDestinations()->latest()->get(),
            'statusPages' => $organization->statusPages()->with('websites')->latest()->get(),
            'websites' => $organization->websites()->orderBy('name')->get(),
            'canManage' => $organization->permits($request->user(), 'manage'),
            'canOperate' => $organization->permits($request->user(), 'operate'),
            'canExportIncidents' => $organization->permits($request->user(), 'operate') || $organization->permits($request->user(), 'audit'),
            'incidents' => $incidents,
            'correlatedBuilds' => Build::query()->whereHas('repository.website', fn ($query) => $query->where('organization_id', $organization->id))->whereIn('status', [Build::STATUS_FAILED, Build::STATUS_CANCELED, Build::STATUS_SUCCEEDED])->with('repository.website')->latest('finished_at')->limit(10)->get(),
            'correlatedHealthChecks' => WebsiteHealthCheck::query()->whereHas('website', fn ($query) => $query->where('organization_id', $organization->id))->where('successful', false)->with('website:id,name')->latest('checked_at')->limit(10)->get(),
            'servers' => $organization->servers()->with(['metrics' => fn ($query) => $query->latest('recorded_at')->limit(24)])->orderBy('name')->get(),
            'metricRules' => $organization->metricAlertRules()->with('server')->latest()->get(),
            'operationalIncidents' => $organization->operationalIncidents()->with(['assignee', 'events.actor'])->latest('last_seen_at')->limit(50)->get(),
            'incidentResponders' => collect([$organization->owner])->merge($organization->members)->unique('id')->sortBy('name'),
        ]);
    }

    /**
     * Require entitled workspace management access and validate a metric, threshold, breach count, cooldown, and optional owned server.
     */
    public function storeMetricRule(StoreMetricAlertRuleRequest $request, CreateMetricAlertRuleAction $createRule): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        $createRule->handle($organization, $request->user(), $request->validated());

        return back()->with('success', __('Metric alert created.'));
    }

    /**
     * Require the metric rule's current-workspace ownership, management access, and alerts entitlement before deleting it.
     */
    public function destroyMetricRule(MetricAlertRule $rule, DeleteMetricAlertRuleAction $deleteRule): RedirectResponse
    {
        $this->authorize('delete', $rule);
        $deleteRule->handle($rule);

        return back()->with('success', __('Metric alert deleted.'));
    }

    /**
     * Validate an entitled workspace manager's alert type, endpoint, and event subscriptions before creating its signing secret.
     *
     * @return RedirectResponse A saved destination or a provider-host validation error.
     */
    public function storeDestination(StoreAlertDestinationRequest $request, CreateAlertDestinationAction $createDestination): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        $createDestination->handle($organization, $request->user(), $request->validated());

        return back()->with('success', __('Alert destination created.'));
    }

    /**
     * Require management access to an entitled workspace alert destination, queue a test delivery, and redirect back.
     */
    public function testDestination(AlertDestination $destination, QueueAlertDestinationTestAction $queueTest): RedirectResponse
    {
        $this->authorize('test', $destination);
        $queueTest->handle($destination);

        return back()->with('success', __('Test alert queued.'));
    }

    /**
     * Require management access to an entitled workspace alert destination, delete it, and redirect back.
     */
    public function destroyDestination(AlertDestination $destination, DeleteAlertDestinationAction $deleteDestination): RedirectResponse
    {
        $this->authorize('delete', $destination);
        $deleteDestination->handle($destination);

        return back()->with('success', __('Alert destination deleted.'));
    }

    /**
     * Validate an entitled workspace manager's page details and website IDs, then atomically create a uniquely slugged status page.
     */
    public function storeStatusPage(StoreStatusPageRequest $request, CreateStatusPageAction $createPage): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        $page = $createPage->handle($organization, $request->user(), $request->validated());

        return back()->with('success', __('Status page created: :url', ['url' => route('status.show', $page->slug)]));
    }

    /**
     * Require management access to the entitled workspace page and atomically update its details and website membership.
     */
    public function updateStatusPage(UpdateStatusPageRequest $request, StatusPage $statusPage, UpdateStatusPageAction $updatePage): RedirectResponse
    {
        $updatePage->handle($statusPage, $request->validated());

        return back()->with('success', __('Status page updated.'));
    }

    /**
     * Require management access to the entitled workspace page, delete its record, and redirect back.
     */
    public function destroyStatusPage(StatusPage $statusPage, DeleteStatusPageAction $deletePage): RedirectResponse
    {
        $this->authorize('delete', $statusPage);
        $deletePage->handle($statusPage);

        return back()->with('success', __('Status page deleted.'));
    }

    /**
     * Validate an entitled workspace manager's status update for an owned page, publish it, and notify subscribers.
     */
    public function storeIncident(Request $request, StatusSubscriberNotifier $notifier): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->permits($request->user(), 'manage'), 403);
        $this->entitlements->enforce($organization, 'status_pages');
        $data = $this->incidentData($request, $organization->id);
        $page = $organization->statusPages()->findOrFail($data['status_page_id']);
        $incident = $page->incidents()->create([
            ...collect($data)->except('status_page_id')->all(),
            'created_by' => $request->user()->id,
            'resolved_at' => in_array($data['status'], ['resolved', 'completed'], true) ? now() : null,
        ]);
        $notifier->send($incident);

        return back()->with('success', __('Status update published.'));
    }

    /**
     * Validate an authorized status-page incident update, maintain its resolution timestamp, and notify subscribers.
     */
    public function updateIncident(Request $request, StatusIncident $incident, StatusSubscriberNotifier $notifier): RedirectResponse
    {
        $this->assertStatusPage($request, $incident->statusPage);
        $this->entitlements->enforce($incident->statusPage->organization, 'status_pages');
        $data = $this->incidentData($request, $incident->statusPage->organization_id, false);
        $incident->update([
            ...collect($data)->except('status_page_id')->all(),
            'resolved_at' => in_array($data['status'], ['resolved', 'completed'], true)
                ? ($incident->resolved_at ?? now()) : null,
        ]);
        $notifier->send($incident->fresh());

        return back()->with('success', __('Status update saved and subscribers notified.'));
    }

    /**
     * Abort with 403 unless the status page belongs to the current workspace and the user can manage it.
     */
    private function assertStatusPage(Request $request, StatusPage $statusPage): void
    {
        abort_unless($statusPage->organization_id === $request->user()->current_organization_id
            && $statusPage->organization->permits($request->user(), 'manage'), 403);
    }

    /**
     * Validate incident/maintenance content, chronology, and status compatibility for an owned status page.
     *
     * @param  bool  $withPage  Require the page ID on creation; updates validate it only if submitted.
     * @return array<string, mixed> Validated update fields, including optional postmortem details.
     */
    private function incidentData(Request $request, int $organizationId, bool $withPage = true): array
    {
        $data = $request->validate([
            'status_page_id' => [$withPage ? 'required' : 'sometimes', 'integer', Rule::exists('status_pages', 'id')->where('organization_id', $organizationId)],
            'kind' => ['required', Rule::in(StatusIncident::KINDS)],
            'status' => ['required', Rule::in(StatusIncident::STATUSES)],
            'severity' => ['required', Rule::in(StatusIncident::SEVERITIES)],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:5000'],
            'remediation' => ['nullable', 'string', 'max:5000'],
            'follow_up' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        $validStatus = $data['kind'] === 'incident'
            ? in_array($data['status'], ['investigating', 'identified', 'monitoring', 'resolved'], true)
            : in_array($data['status'], ['scheduled', 'in_progress', 'completed'], true);
        if (! $validStatus) {
            throw ValidationException::withMessages(['status' => __('Choose a status that matches the update type.')]);
        }

        return $data;
    }
}
