<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Actions\Observability\CreateAlertDestinationAction;
use App\Modules\Deployer\Actions\Observability\CreateMetricAlertRuleAction;
use App\Modules\Deployer\Actions\Observability\CreateObservabilityInvestigationViewAction;
use App\Modules\Deployer\Actions\Observability\CreateStatusIncidentAction;
use App\Modules\Deployer\Actions\Observability\CreateStatusPageAction;
use App\Modules\Deployer\Actions\Observability\DeleteAlertDestinationAction;
use App\Modules\Deployer\Actions\Observability\DeleteMetricAlertRuleAction;
use App\Modules\Deployer\Actions\Observability\DeleteObservabilityInvestigationViewAction;
use App\Modules\Deployer\Actions\Observability\DeleteStatusPageAction;
use App\Modules\Deployer\Actions\Observability\QueueAlertDestinationTestAction;
use App\Modules\Deployer\Actions\Observability\RetryAlertOutboundDeliveryAction;
use App\Modules\Deployer\Actions\Observability\UpdateStatusIncidentAction;
use App\Modules\Deployer\Actions\Observability\UpdateStatusPageAction;
use App\Modules\Deployer\Data\ObservabilityInvestigationViewData;
use App\Modules\Deployer\Http\Requests\ObservabilityContextRequest;
use App\Modules\Deployer\Http\Requests\RetryAlertOutboundDeliveryRequest;
use App\Modules\Deployer\Http\Requests\StoreAlertDestinationRequest;
use App\Modules\Deployer\Http\Requests\StoreMetricAlertRuleRequest;
use App\Modules\Deployer\Http\Requests\StoreObservabilityInvestigationViewRequest;
use App\Modules\Deployer\Http\Requests\StoreStatusIncidentRequest;
use App\Modules\Deployer\Http\Requests\StoreStatusPageRequest;
use App\Modules\Deployer\Http\Requests\UpdateStatusIncidentRequest;
use App\Modules\Deployer\Http\Requests\UpdateStatusPageRequest;
use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Models\AlertOutboundDelivery;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\MetricAlertRule;
use App\Modules\Deployer\Models\ObservabilityInvestigationView;
use App\Modules\Deployer\Models\OperationalIncident;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\StatusIncident;
use App\Modules\Deployer\Models\StatusPage;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use App\Modules\Deployer\Services\ObservabilityDashboardQuery;
use App\Modules\Deployer\Services\ObservabilityEnvironmentContextQuery;
use App\Modules\Deployer\Services\ObservabilityInvestigationViewQuery;
use App\Modules\Deployer\Services\ObservabilityInvestigationViewResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ObservabilityController extends Controller
{
    /**
     * Render current-workspace alerts, bounded incident/deployment/health context, metrics, and responder permissions.
     */
    public function index(Request $request, ObservabilityDashboardQuery $dashboard): View
    {
        $organization = $request->user()->currentOrganization;

        if ($request->string('fragment')->toString() === 'operational-incident') {
            $incidentId = filter_var($request->query('incident_id'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            abort_if($incidentId === false, 404);

            $incident = $organization->operationalIncidents()
                ->tap(fn ($query) => app(DeployerProjectAccess::class)->incidents($query, $request->user()))
                ->with(['assignee', 'events.actor'])
                ->whereKey($incidentId)
                ->first();
            abort_if($incident === null, 404);

            return view('components.scenes.observability.operational-incident-content', [
                'incident' => $incident,
            ]);
        }

        $data = $dashboard->for($organization, $request->user());

        return view('observability.index', [
            ...$data,
            'canManage' => $organization->permits($request->user(), 'manage'),
            'canOperate' => $organization->permits($request->user(), 'operate'),
            'canExportIncidents' => $organization->permits($request->user(), 'operate') || $organization->permits($request->user(), 'audit'),
            'selectedOperationalIncident' => $this->selectedOperationalIncident($request, $organization),
        ]);
    }

    private function selectedOperationalIncident(Request $request, Organization $organization): ?OperationalIncident
    {
        $dialog = $request->string('dialog')->toString();

        if (! preg_match('/^operational-incident-(\d+)$/', $dialog, $matches)) {
            return null;
        }

        return $organization->operationalIncidents()
            ->tap(fn ($query) => app(DeployerProjectAccess::class)->incidents($query, $request->user()))
            ->with(['assignee', 'events.actor'])
            ->whereKey((int) $matches[1])
            ->first();
    }

    /**
     * Render bounded deployment, health, runtime-log metadata, and related incident evidence for one environment.
     */
    public function environmentContext(
        ObservabilityContextRequest $request,
        Environment $environment,
        ObservabilityEnvironmentContextQuery $context,
        ObservabilityInvestigationViewQuery $investigations,
    ): View {
        $filters = $request->filters();

        return view('observability.environment-context', [
            'context' => $context->for($environment, $filters),
            'savedInvestigations' => $investigations->for($request->user(), $environment),
            'canManageInvestigationViews' => $environment->project->organization->permits($request->user(), 'manage'),
            'shareUrl' => route('observability.environments.context', [
                'environment' => $environment,
                ...$filters->queryParameters(),
            ]),
        ]);
    }

    /** Persist a bounded named investigation view using the existing normalized context filters. */
    public function storeInvestigation(
        StoreObservabilityInvestigationViewRequest $request,
        Environment $environment,
        CreateObservabilityInvestigationViewAction $createInvestigation,
    ): RedirectResponse {
        $createInvestigation->handle(
            $request->user()->currentOrganization,
            $environment,
            $request->user(),
            new ObservabilityInvestigationViewData(
                name: $request->viewName(),
                filters: $request->filters(),
                expiresInDays: $request->expirationDays(),
            ),
        );

        return back()->with('success', __('Investigation view saved.'));
    }

    /** Reauthorize a named view and redirect to the existing canonical evidence context. */
    public function showInvestigation(
        ObservabilityInvestigationView $view,
        ObservabilityInvestigationViewResolver $resolver,
    ): RedirectResponse {
        if ($view->isExpired()) {
            abort(404);
        }

        $this->authorize('view', $view);
        $filters = $resolver->filters($view);

        if ($filters === null) {
            abort(404);
        }

        return redirect()->route('observability.environments.context', [
            'environment' => $view->environment,
            ...$filters->queryParameters(),
        ]);
    }

    /** Reauthorize and remove one named investigation view without affecting its evidence records. */
    public function destroyInvestigation(
        Request $request,
        ObservabilityInvestigationView $view,
        DeleteObservabilityInvestigationViewAction $deleteInvestigation,
    ): RedirectResponse {
        $this->authorize('delete', $view);
        $deleteInvestigation->handle($view);

        return back()->with('success', __('Investigation view removed.'));
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

    /** Require current-workspace manager authorization and explicit confirmation before the single bounded outbound retry. */
    public function retryAlertDelivery(
        RetryAlertOutboundDeliveryRequest $request,
        AlertOutboundDelivery $delivery,
        RetryAlertOutboundDeliveryAction $retryDelivery,
    ): RedirectResponse {
        $this->authorize('retry', $delivery);
        $retryDelivery->handle($delivery, $request->user());

        return to_route('observability.index', ['section' => 'alert-delivery-history'])
            ->with('success', __('Alert delivery retry queued.'));
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
