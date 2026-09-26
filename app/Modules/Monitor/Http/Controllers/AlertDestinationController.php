<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Data\Telemetry\AlertDestinationType;
use App\Modules\Monitor\Http\Requests\ChangeAlertDestinationRequest;
use App\Modules\Monitor\Http\Requests\SaveAlertDestinationRequest;
use App\Modules\Monitor\Http\Requests\SearchAlertDestinationsRequest;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Services\AlertNotificationTransport;
use App\Modules\Monitor\Services\ChangeAlertDestination;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;

class AlertDestinationController extends Controller
{
    public function index(SearchAlertDestinationsRequest $request, CurrentWorkspace $workspace, AlertNotificationTransport $transport, TelemetryRedactor $redactor): Response
    {
        $state = $request->validated('state') ?? 'all';
        $query = AlertDestination::forWorkspace($workspace->get())->with('recipient:id,name')->withCount(['alertRules' => fn ($query) => $query->visibleTo($request->user(), $workspace->get()), 'monitors' => fn ($query) => $query->visibleTo($request->user(), $workspace->get())]);
        if ($state === 'archived') {
            $query->onlyTrashed();
        } elseif ($state !== 'all') {
            $query->where('enabled', $state === 'enabled');
        }
        $destinations = $query->latest('created_at')->latest('id')->paginate(25, ['*'], 'page', (int) ($request->validated('page') ?? 1))
            ->appends($request->safe()->except('page'));
        $destinations->each(fn (AlertDestination $destination): AlertDestination => $destination->forceFill($redactor->redact($destination->only('name'))));

        return response()->view('monitor::alerts.destinations', [
            ...compact('destinations', 'state'), 'mailConfigured' => $transport->mailConfigured(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function create(SearchAlertDestinationsRequest $request, CurrentWorkspace $workspace): Response
    {
        return $this->form($workspace);
    }

    public function edit(SearchAlertDestinationsRequest $request, AlertDestination $alertDestination, CurrentWorkspace $workspace): Response
    {
        return $this->form($workspace, $alertDestination);
    }

    private function form(CurrentWorkspace $workspace, ?AlertDestination $destination = null): Response
    {
        $members = $workspace->get()->members()->whereNotNull('email_verified_at')->orderBy('name')->orderBy('users.id')->get(['users.id', 'name']);
        $types = collect(AlertDestinationType::cases())->mapWithKeys(fn (AlertDestinationType $type): array => [$type->value => $type->label()])->all();
        $recipients = $members->mapWithKeys(fn (User $member): array => [$member->id => $member->name])->all();

        return response()->view('monitor::alerts.destination-form', compact('destination', 'types', 'recipients'))->header('Cache-Control', 'private, no-store');
    }

    public function store(SaveAlertDestinationRequest $request, CurrentWorkspace $workspace, ChangeAlertDestination $changes): RedirectResponse
    {
        $destination = $changes->save($workspace->get(), $request->user(), $request->validated());

        return $this->saved($destination, 'Destination created. Route an alert rule or send an explicit test.', $destination->type === AlertDestinationType::Webhook);
    }

    public function update(SaveAlertDestinationRequest $request, AlertDestination $alertDestination, CurrentWorkspace $workspace, ChangeAlertDestination $changes): RedirectResponse
    {
        $destination = $changes->save($workspace->get(), $request->user(), $request->validated(), $alertDestination);

        return $this->saved($destination, 'Destination saved. Queued deliveries to the previous target will be cancelled.');
    }

    public function show(SearchAlertDestinationsRequest $request, AlertDestination $alertDestination, AlertNotificationTransport $transport, TelemetryRedactor $redactor): Response
    {
        $alertDestination->load('recipient:id,name');
        $alertDestination->forceFill($redactor->redact($alertDestination->only('name')));
        $deliveries = $alertDestination->deliveries()->visibleTo($request->user(), $alertDestination->workspace)->where('workspace_id', $alertDestination->workspace_id)
            ->latest('created_at')->latest('id')->paginate(25, ['*'], 'deliveries_page', (int) ($request->validated('deliveries_page') ?? 1));
        $flash = $request->session()->get('issued_alert_key');
        $issuedSecret = is_array($flash) && ($flash['destination_id'] ?? null) === $alertDestination->id
            ? Crypt::decryptString($flash['encrypted_secret']) : null;
        if ($issuedSecret !== null) {
            $request->session()->forget('issued_alert_key');
        }

        return response()->view('monitor::alerts.destination-show', [
            'destination' => $alertDestination, 'deliveries' => $deliveries, 'issuedSecret' => $issuedSecret,
            'mailConfigured' => $transport->mailConfigured(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(ChangeAlertDestinationRequest $request, AlertDestination $alertDestination, CurrentWorkspace $workspace, ChangeAlertDestination $changes): RedirectResponse
    {
        $changes->change($workspace->get(), $request->user(), $alertDestination, (int) $request->validated('version'), 'archive');

        return to_route('monitor.alert-destinations.index', ['state' => 'archived'])->with('status', 'Destination archived. Delivery history is retained; in-flight requests may finish.');
    }

    public function rotate(ChangeAlertDestinationRequest $request, AlertDestination $alertDestination, CurrentWorkspace $workspace, ChangeAlertDestination $changes): RedirectResponse
    {
        $destination = $changes->change($workspace->get(), $request->user(), $alertDestination, (int) $request->validated('version'), 'rotate');

        return $this->saved($destination, 'Signing key rotated. Update your receiver before routing new alerts. In-flight requests may use the previous key.', true);
    }

    private function saved(AlertDestination $destination, string $message, bool $showSecret = false): RedirectResponse
    {
        $response = to_route('monitor.alert-destinations.show', $destination)->with('status', $message);

        return $showSecret ? $response->with('issued_alert_key', [
            'destination_id' => $destination->id, 'encrypted_secret' => Crypt::encryptString($destination->signing_secret),
        ]) : $response;
    }
}
