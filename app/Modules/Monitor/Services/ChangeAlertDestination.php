<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\AlertDestinationType;
use App\Modules\Monitor\Models\AlertDestination;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ChangeAlertDestination
{
    public function __construct(private readonly PublicWebhookTarget $targets, private readonly TelemetryRedactor $redactor, private readonly RecordAuditLog $audit) {}

    /** @param array<string, mixed> $data */
    public function save(Workspace $workspace, User $actor, array $data, ?AlertDestination $destination = null): AlertDestination
    {
        return DB::connection('monitor')->transaction(function () use ($workspace, $actor, $data, $destination): AlertDestination {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('update', $workspace);
            $new = $destination === null;
            if (! $new) {
                $destination = AlertDestination::forWorkspace($workspace)->lockForUpdate()->findOrFail($destination->id);
                $this->version($destination, (int) $data['version']);
            }
            $destination ??= new AlertDestination;
            $before = $new ? null : [
                'name' => $destination->name,
                'enabled' => $destination->enabled,
                'recipient_user_id' => $destination->recipient_user_id,
                'endpoint_host' => parse_url($destination->endpoint_url ?? '', PHP_URL_HOST) ?: null,
            ];
            $type = $new ? AlertDestinationType::from($data['type']) : $destination->type;
            $recipientId = null;
            $endpoint = null;
            $secret = null;
            if ($type === AlertDestinationType::Email) {
                $recipientId = $workspace->members()->whereNotNull('email_verified_at')->whereKey($data['recipient_user_id'] ?? 0)->value('users.id');
                if ($recipientId === null) {
                    throw ValidationException::withMessages(['recipient_user_id' => 'Choose a verified member of this workspace.']);
                }
            } elseif ($type === AlertDestinationType::PagerDuty) {
                $endpoint = 'https://events.pagerduty.com/v2/enqueue';
                $secret = filled($data['signing_secret'] ?? null) ? $data['signing_secret'] : $destination->signing_secret;
                if (! is_string($secret) || $secret === '') {
                    throw ValidationException::withMessages(['signing_secret' => 'Enter the PagerDuty integration routing key.']);
                }
            } else {
                $endpoint = $data['endpoint_url'] ?? $destination->endpoint_url;
                if (! is_string($endpoint) || $this->targets->host($endpoint, $type) === null) {
                    throw ValidationException::withMessages(['endpoint_url' => 'Use a valid public HTTPS destination on port 443 for this provider.']);
                }
            }
            $destination->forceFill([
                'workspace_id' => $workspace->id, 'type' => $type,
                'name' => $this->redactor->redact(['name' => $data['name']])['name'],
                'enabled' => (bool) $data['enabled'], 'recipient_user_id' => $recipientId, 'endpoint_url' => $endpoint,
            ]);
            if ($type === AlertDestinationType::PagerDuty) {
                $destination->forceFill(['signing_secret' => $secret]);
            } elseif ($type !== AlertDestinationType::Webhook) {
                $destination->forceFill(['signing_secret' => null]);
            }
            if ($new && $type === AlertDestinationType::Webhook) {
                $destination->forceFill(['signing_secret' => Str::random(64)]);
            }
            $changed = $new || $destination->isDirty(['name', 'enabled', 'recipient_user_id', 'endpoint_url', 'signing_secret']);
            if ($new || $destination->isDirty()) {
                $destination->forceFill([
                    'state_version' => $new ? 0 : $destination->state_version + 1,
                    'target_revision' => $new ? 0 : $destination->target_revision + (int) ($changed && $destination->isDirty(['enabled', 'recipient_user_id', 'endpoint_url'])),
                ])->save();
            }

            if ($new || $changed) {
                $this->audit->record($workspace, $actor, $new ? 'alert_destination.created' : 'alert_destination.updated', $destination, [
                    'label' => $destination->name, 'type' => $type->value, 'enabled' => $destination->enabled,
                    ...($new ? [] : ['before' => $before]),
                ]);
            }

            return $destination;
        }, attempts: 3);
    }

    public function change(Workspace $workspace, User $actor, AlertDestination $destination, int $version, string $action): AlertDestination
    {
        return DB::connection('monitor')->transaction(function () use ($workspace, $actor, $destination, $version, $action): AlertDestination {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            Gate::forUser($actor)->authorize('update', $workspace);
            $destination = AlertDestination::forWorkspace($workspace)->lockForUpdate()->findOrFail($destination->id);
            $this->version($destination, $version);
            if ($action === 'rotate') {
                abort_unless($destination->type === AlertDestinationType::Webhook, 409, 'Only signed webhooks have a signing key.');
                $destination->forceFill(['signing_secret' => Str::random(64)]);
            } else {
                $destination->forceFill(['enabled' => false]);
            }
            $destination->forceFill(['state_version' => $destination->state_version + 1, 'target_revision' => $destination->target_revision + 1])->save();
            if ($action === 'archive') {
                $destination->delete();
            }
            $this->audit->record($workspace, $actor, $action === 'rotate' ? 'alert_destination.rotated' : 'alert_destination.archived', $destination, [
                'label' => $destination->name, 'type' => $destination->type->value,
            ]);

            return $destination;
        }, attempts: 3);
    }

    private function version(AlertDestination $destination, int $version): void
    {
        abort_unless($destination->state_version === $version, 409, 'This destination changed. Refresh before trying again.');
    }
}
