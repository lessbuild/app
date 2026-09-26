<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\WorkspaceMonitorDestinationAdministrationProvider;
use App\Core\Data\Monitor\MonitorAdministrationSnapshot;
use App\Core\Data\Monitor\MonitorMutationResult;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Monitor\Data\Telemetry\AlertDestinationType;
use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\AlertDeliveryAttempt;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\ChangeAlertDestination;
use App\Modules\Monitor\Services\DeliverAlertNotification;
use App\Modules\Monitor\Services\RecordAlertDeliveries;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Illuminate\Support\Facades\Gate;

final class MonitorDestinationAdministrationProvider implements WorkspaceMonitorDestinationAdministrationProvider
{
    private readonly MonitorAdministrationContext $context;

    public function __construct(
        LegacyIdentityResolver $identities,
        WorkspaceProjectAccess $workspaceAccess,
        ProductWorkspaceAccess $productAccess,
        private readonly TelemetryRedactor $redactor,
        private readonly ChangeAlertDestination $destinations,
        private readonly RecordAlertDeliveries $recordDeliveries,
        private readonly DeliverAlertNotification $deliveries,
    ) {
        $this->context = new MonitorAdministrationContext($identities, $workspaceAccess, $productAccess);
    }

    public function snapshot(PlatformUser $user, CoreWorkspace $workspace, array $filters = []): MonitorAdministrationSnapshot
    {
        $this->context->resetReferences();
        $resolved = $this->context->resolve($user, $workspace);
        if ($resolved === null) {
            return new MonitorAdministrationSnapshot(collect(), available: false);
        }
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $resolved;

        $canManage = Gate::forUser($actor)->allows('update', $sourceWorkspace);
        $destinations = AlertDestination::withTrashed()->forWorkspace($sourceWorkspace)
            ->when(! $canManage, fn ($query) => $query->whereRaw('1 = 0'))
            ->when(filled($filters['destination_search'] ?? null), fn ($query) => $query->where(function ($query) use ($filters): void {
                $search = '%'.trim((string) $filters['destination_search']).'%';
                $query->where('name', 'like', $search)->orWhere('type', 'like', $search);
            }))->with('recipient:id,name')->withCount('alertRules')->latest('created_at')->latest('id')
            ->paginate(20, ['*'], 'destination_page')->withQueryString();
        $destinationModels = $destinations->getCollection()->filter(fn (AlertDestination $destination): bool => Gate::forUser($actor)->allows('view', $destination));
        $destinations->setCollection($destinationModels);
        $items = $destinationModels->map(function (AlertDestination $destination) use ($sourceWorkspace, $actor): array {
            $canUpdate = Gate::forUser($actor)->allows('update', $destination);
            $destination->forceFill($this->redactor->redact($destination->only(['name'])));

            return [
                'kind' => 'destination',
                'reference' => $this->context->reference('destination', $destination->getKey(), $sourceWorkspace),
                'name' => (string) $destination->name,
                'type' => $destination->type->value,
                'type_label' => $destination->type->label(),
                'target' => $destination->targetLabel(),
                'recipient_reference' => $destination->recipient_user_id === null ? null : $this->context->reference('recipient', $destination->recipient_user_id, $sourceWorkspace),
                'enabled' => (bool) $destination->enabled,
                'archived' => $destination->trashed(),
                'version' => (int) $destination->state_version,
                'rule_count' => (int) $destination->alert_rules_count,
                'can_update' => $canUpdate,
                'delivery_destination_reference' => $this->context->reference('destination', $destination->getKey(), $sourceWorkspace),
            ];
        })->values();
        $recipients = $sourceWorkspace->members()->whereNotNull('email_verified_at')
            ->when(! $canManage, fn ($query) => $query->whereRaw('1 = 0'))
            ->when(filled($filters['recipient_search'] ?? null), fn ($query) => $query->where('name', 'like', '%'.trim((string) $filters['recipient_search']).'%'))
            ->orderBy('name')->orderBy('users.id')->paginate(25, ['users.id', 'name'], 'recipient_page')->withQueryString();
        $selectedRecipientIds = $destinationModels->pluck('recipient_user_id')->filter()->unique();
        $selectedRecipients = $sourceWorkspace->members()->whereKey($selectedRecipientIds)->get(['users.id', 'name', 'email_verified_at']);
        $recipients->setCollection($recipients->getCollection()->merge($selectedRecipients)->unique('id')->sortBy('name')->values());

        $selectedDestination = null;
        $deliveryHistory = null;
        if (filled($filters['delivery_destination'] ?? null)) {
            $selectedDestinationId = $this->context->sourceId((string) $filters['delivery_destination'], 'destination', $sourceWorkspace);
            $selectedDestination = AlertDestination::withTrashed()->forWorkspace($sourceWorkspace)->findOrFail($selectedDestinationId);
            Gate::forUser($actor)->authorize('view', $selectedDestination);
            $deliveryHistory = $selectedDestination->deliveries()->where('workspace_id', $sourceWorkspace->getKey())
                ->visibleTo($actor, $sourceWorkspace)
                ->when(filled($filters['delivery_search'] ?? null), fn ($query) => $query->where(function ($query) use ($filters): void {
                    $search = '%'.trim((string) $filters['delivery_search']).'%';
                    $query->where('event', 'like', $search)->orWhere('status', 'like', $search)->orWhere('last_error_code', 'like', $search);
                }))->latest('created_at')->latest('id')->paginate(20, ['*'], 'delivery_page')->withQueryString();
        }

        $destinationPage = clone $destinations;
        $destinationPage->setCollection($items);

        return new MonitorAdministrationSnapshot($destinationPage, [
            'recipients' => $recipients->through(fn (User $recipient): array => [
                'reference' => $this->context->reference('recipient', $recipient->getKey(), $sourceWorkspace),
                'name' => (string) $recipient->name.($recipient->email_verified_at === null ? ' · '.__('Unverified') : ''),
            ]),
            'delivery_destination' => $selectedDestination === null ? null : [
                'reference' => $this->context->reference('destination', $selectedDestination->getKey(), $sourceWorkspace),
                'name' => (string) $this->redactor->redact($selectedDestination->only(['name']))['name'],
                'can_update' => Gate::forUser($actor)->allows('update', $selectedDestination),
            ],
            'deliveries' => $deliveryHistory?->through(fn (AlertDelivery $delivery): array => $this->deliveryRow($delivery, $sourceWorkspace)),
            'types' => collect(AlertDestinationType::cases())->map(fn (AlertDestinationType $type): array => ['value' => $type->value, 'label' => $type->label()])->all(),
            'can_create' => Gate::forUser($actor)->allows('create', [AlertDestination::class, $sourceWorkspace]),
        ]);
    }

    public function saveDestination(PlatformUser $user, CoreWorkspace $workspace, ?string $destinationReference, array $data): MonitorMutationResult
    {
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $destination = null;
        if ($destinationReference !== null) {
            $id = $this->context->sourceId($destinationReference, 'destination', $sourceWorkspace);
            $destination = AlertDestination::forWorkspace($sourceWorkspace)->findOrFail($id);
        } else {
            Gate::forUser($actor)->authorize('create', [AlertDestination::class, $sourceWorkspace]);
        }
        if (filled($data['recipient_reference'] ?? null)) {
            $data['recipient_user_id'] = $this->context->sourceId((string) $data['recipient_reference'], 'recipient', $sourceWorkspace);
        }
        unset($data['recipient_reference']);
        $saved = $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, fn (Workspace $locked): AlertDestination => $this->destinations->save($locked, $actor, $data, $destination));

        return new MonitorMutationResult(
            true,
            'Alert destination saved.',
            $this->context->reference('destination', $saved->getKey(), $sourceWorkspace),
            $saved->type === AlertDestinationType::Webhook && $saved->wasRecentlyCreated ? $saved->signing_secret : null,
        );
    }

    public function archiveDestination(PlatformUser $user, CoreWorkspace $workspace, string $destinationReference, int $version): MonitorMutationResult
    {
        return $this->changeDestination($user, $workspace, $destinationReference, $version, 'archive');
    }

    public function rotateDestination(PlatformUser $user, CoreWorkspace $workspace, string $destinationReference, int $version): MonitorMutationResult
    {
        return $this->changeDestination($user, $workspace, $destinationReference, $version, 'rotate');
    }

    public function testDestination(PlatformUser $user, CoreWorkspace $workspace, string $destinationReference, int $version): MonitorMutationResult
    {
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $id = $this->context->sourceId($destinationReference, 'destination', $sourceWorkspace);
        $destination = AlertDestination::forWorkspace($sourceWorkspace)->findOrFail($id);
        $delivery = $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, fn (Workspace $locked): AlertDelivery => $this->recordDeliveries->test($locked, $actor, $destination, $version));

        return new MonitorMutationResult(true, 'Test notification queued.', $this->context->reference('delivery', $delivery->getKey(), $sourceWorkspace));
    }

    public function retryDelivery(PlatformUser $user, CoreWorkspace $workspace, string $deliveryReference, int $generation): MonitorMutationResult
    {
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $id = $this->context->sourceId($deliveryReference, 'delivery', $sourceWorkspace);
        $delivery = AlertDelivery::query()->where('workspace_id', $sourceWorkspace->getKey())->findOrFail($id);
        Gate::forUser($actor)->authorize('update', $delivery);
        $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, function (Workspace $locked) use ($actor, $delivery, $generation): void {
            $this->deliveries->retry($locked, $actor, $delivery, $generation);
        });

        return new MonitorMutationResult(true, 'Retry queued using the current destination configuration.');
    }

    /** @return array<string, mixed> */
    private function deliveryRow(AlertDelivery $delivery, Workspace $sourceWorkspace): array
    {
        return [
            'reference' => $this->context->reference('delivery', $delivery->getKey(), $sourceWorkspace),
            'status' => $delivery->status->value,
            'status_label' => $delivery->status->label(),
            'event' => (string) $delivery->event,
            'attempt_count' => (int) $delivery->attempt_count,
            'generation' => (int) $delivery->generation,
            'retryable' => $delivery->status->retryable(),
            'created_at' => $delivery->created_at?->toImmutable()->utc(),
            'last_error' => $delivery->last_error_code,
            'attempts' => AlertDeliveryAttempt::query()->where('alert_delivery_id', $delivery->getKey())
                ->latest('number')->limit(5)->get(['number', 'status', 'error_code', 'http_status', 'started_at', 'finished_at'])
                ->map(fn (AlertDeliveryAttempt $attempt): array => [
                    'number' => (int) $attempt->number,
                    'status' => $attempt->status->label(),
                    'error_code' => $attempt->error_code,
                    'http_status' => $attempt->http_status,
                    'started_at' => $attempt->started_at,
                    'finished_at' => $attempt->finished_at,
                ])->all(),
        ];
    }

    private function changeDestination(PlatformUser $user, CoreWorkspace $workspace, string $reference, int $version, string $action): MonitorMutationResult
    {
        ['workspace' => $sourceWorkspace, 'user' => $actor] = $this->requiredContext($user, $workspace);
        $id = $this->context->sourceId($reference, 'destination', $sourceWorkspace);
        $destination = AlertDestination::forWorkspace($sourceWorkspace)->findOrFail($id);
        $saved = $this->context->mutate($user, $workspace, $sourceWorkspace, $actor, fn (Workspace $locked): AlertDestination => $this->destinations->change($locked, $actor, $destination, $version, $action));

        return new MonitorMutationResult(true, $action === 'rotate' ? 'Signing key rotated.' : 'Alert destination archived.',
            $this->context->reference('destination', $saved->getKey(), $sourceWorkspace),
            $action === 'rotate' ? $saved->signing_secret : null);
    }

    /** @return array{workspace: Workspace, user: User} */
    private function requiredContext(PlatformUser $user, CoreWorkspace $workspace): array
    {
        $resolved = $this->context->resolve($user, $workspace);
        abort_unless($resolved !== null, 404);
        Gate::forUser($resolved['user'])->authorize('update', $resolved['workspace']);

        return $resolved;
    }
}
