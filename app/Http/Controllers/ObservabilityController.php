<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverAlertWebhookJob;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ObservabilityController extends Controller
{
    public function __construct(private readonly Entitlements $entitlements) {}

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

    public function storeMetricRule(Request $request): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->permits($request->user(), 'manage'), 403);
        $this->entitlements->enforce($organization, 'alerts');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'server_id' => ['nullable', Rule::exists('servers', 'id')->where('organization_id', $organization->id)],
            'metric' => ['required', Rule::in(MetricAlertRule::METRICS)],
            'operator' => ['required', Rule::in(['gte', 'lte'])],
            'threshold' => ['required', 'numeric', 'between:0,999999999'],
            'consecutive_breaches' => ['required', 'integer', 'between:1,10'],
            'cooldown_minutes' => ['required', 'integer', Rule::in([5, 15, 30, 60, 180, 1440])],
        ]);
        $organization->metricAlertRules()->create([
            ...$data, 'created_by' => $request->user()->id, 'is_enabled' => true,
        ]);

        return back()->with('success', __('Metric alert created.'));
    }

    public function destroyMetricRule(Request $request, MetricAlertRule $rule): RedirectResponse
    {
        abort_unless($rule->organization_id === $request->user()->current_organization_id
            && $rule->organization->permits($request->user(), 'manage'), 403);
        $this->entitlements->enforce($rule->organization, 'alerts');
        $rule->delete();

        return back()->with('success', __('Metric alert deleted.'));
    }

    public function storeDestination(Request $request): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->permits($request->user(), 'manage'), 403);
        $this->entitlements->enforce($organization, 'alerts');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(AlertDestination::TYPES)],
            'endpoint' => ['required', 'string', 'max:2000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(AlertDestination::EVENTS)],
        ]);
        if ($data['type'] === 'email' && ! filter_var($data['endpoint'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['endpoint' => __('Enter a valid email address.')]);
        }
        if ($data['type'] === 'pagerduty' && ! preg_match('/\A[a-zA-Z0-9_-]{20,100}\z/D', $data['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => __('Enter a valid PagerDuty Events API routing key.')]);
        }
        $usesWebhookUrl = ! in_array($data['type'], ['email', 'pagerduty'], true);
        if ($usesWebhookUrl && (! filter_var($data['endpoint'], FILTER_VALIDATE_URL)
            || parse_url($data['endpoint'], PHP_URL_SCHEME) !== 'https')) {
            throw ValidationException::withMessages(['endpoint' => __('Webhook destinations must use a valid HTTPS URL.')]);
        }
        $host = parse_url($data['endpoint'], PHP_URL_HOST);
        if ($data['type'] === 'slack' && $host !== 'hooks.slack.com') {
            return back()->withErrors(['endpoint' => __('Slack destinations must use hooks.slack.com.')])->withInput();
        }
        if ($data['type'] === 'discord' && ! in_array($host, ['discord.com', 'discordapp.com'], true)) {
            return back()->withErrors(['endpoint' => __('Discord destinations must use a Discord webhook URL.')])->withInput();
        }
        $organization->alertDestinations()->create([
            ...$data,
            'created_by' => $request->user()->id,
            'signing_secret' => bin2hex(random_bytes(32)),
            'is_active' => true,
        ]);

        return back()->with('success', __('Alert destination created.'));
    }

    public function testDestination(Request $request, AlertDestination $destination): RedirectResponse
    {
        $this->assertDestination($request, $destination);
        $this->entitlements->enforce($destination->organization, 'alerts');
        DeliverAlertWebhookJob::dispatch($destination->id, [
            'id' => (string) Str::uuid(),
            'event' => 'failure',
            'category' => 'test',
            'resource_id' => 0,
            'title' => 'BuildPusher test alert',
            'message' => 'Your alert destination is connected.',
            'occurred_at' => now()->toIso8601String(),
        ]);

        return back()->with('success', __('Test alert queued.'));
    }

    public function destroyDestination(Request $request, AlertDestination $destination): RedirectResponse
    {
        $this->assertDestination($request, $destination);
        $this->entitlements->enforce($destination->organization, 'alerts');
        $destination->delete();

        return back()->with('success', __('Alert destination deleted.'));
    }

    public function storeStatusPage(Request $request): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->permits($request->user(), 'manage'), 403);
        $this->entitlements->enforce($organization, 'status_pages');
        $data = $this->statusPageData($request, $organization->id);
        $base = Str::slug($data['slug'] ?: $data['name']) ?: 'status';
        $slug = $base;
        while (StatusPage::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }
        $page = DB::transaction(function () use ($organization, $request, $data, $slug): StatusPage {
            $page = $organization->statusPages()->create([
                'created_by' => $request->user()->id,
                'name' => $data['name'],
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'is_published' => $data['is_published'],
            ]);
            $page->websites()->sync($data['website_ids']);

            return $page;
        });

        return back()->with('success', __('Status page created: :url', ['url' => route('status.show', $page->slug)]));
    }

    public function updateStatusPage(Request $request, StatusPage $statusPage): RedirectResponse
    {
        $this->assertStatusPage($request, $statusPage);
        $this->entitlements->enforce($statusPage->organization, 'status_pages');
        $data = $this->statusPageData($request, $statusPage->organization_id, false);
        DB::transaction(function () use ($statusPage, $data): void {
            $statusPage->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_published' => $data['is_published'],
            ]);
            $statusPage->websites()->sync($data['website_ids']);
        });

        return back()->with('success', __('Status page updated.'));
    }

    public function destroyStatusPage(Request $request, StatusPage $statusPage): RedirectResponse
    {
        $this->assertStatusPage($request, $statusPage);
        $this->entitlements->enforce($statusPage->organization, 'status_pages');
        $statusPage->delete();

        return back()->with('success', __('Status page deleted.'));
    }

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

    private function assertDestination(Request $request, AlertDestination $destination): void
    {
        abort_unless($destination->organization_id === $request->user()->current_organization_id
            && $destination->organization->permits($request->user(), 'manage'), 403);
    }

    private function assertStatusPage(Request $request, StatusPage $statusPage): void
    {
        abort_unless($statusPage->organization_id === $request->user()->current_organization_id
            && $statusPage->organization->permits($request->user(), 'manage'), 403);
    }

    private function statusPageData(Request $request, int $organizationId, bool $withSlug = true): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => [$withSlug ? 'nullable' : 'sometimes', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_published' => ['required', 'boolean'],
            'website_ids' => ['required', 'array', 'min:1'],
            'website_ids.*' => ['integer', Rule::exists('websites', 'id')->where('organization_id', $organizationId)],
        ]);
    }

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
