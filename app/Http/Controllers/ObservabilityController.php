<?php

namespace App\Http\Controllers;

use App\Actions\Observability\CreateAlertDestinationAction;
use App\Actions\Observability\CreateMetricAlertRuleAction;
use App\Actions\Observability\CreateStatusIncidentAction;
use App\Actions\Observability\CreateStatusPageAction;
use App\Actions\Observability\DeleteAlertDestinationAction;
use App\Actions\Observability\DeleteMetricAlertRuleAction;
use App\Actions\Observability\DeleteStatusPageAction;
use App\Actions\Observability\QueueAlertDestinationTestAction;
use App\Actions\Observability\UpdateStatusIncidentAction;
use App\Actions\Observability\UpdateStatusPageAction;
use App\Http\Requests\StoreAlertDestinationRequest;
use App\Http\Requests\StoreMetricAlertRuleRequest;
use App\Http\Requests\StoreStatusIncidentRequest;
use App\Http\Requests\StoreStatusPageRequest;
use App\Http\Requests\UpdateStatusIncidentRequest;
use App\Http\Requests\UpdateStatusPageRequest;
use App\Models\AlertDestination;
use App\Models\Build;
use App\Models\MetricAlertRule;
use App\Models\StatusIncident;
use App\Models\StatusPage;
use App\Models\WebsiteHealthCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ObservabilityController extends Controller
{
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
    public function storeIncident(StoreStatusIncidentRequest $request, CreateStatusIncidentAction $createIncident): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        $createIncident->handle($organization, $request->user(), $request->validated());

        return back()->with('success', __('Status update published.'));
    }

    /**
     * Validate an authorized status-page incident update, maintain its resolution timestamp, and notify subscribers.
     */
    public function updateIncident(UpdateStatusIncidentRequest $request, StatusIncident $incident, UpdateStatusIncidentAction $updateIncident): RedirectResponse
    {
        $updateIncident->handle($incident, $request->validated());

        return back()->with('success', __('Status update saved and subscribers notified.'));
    }
}
