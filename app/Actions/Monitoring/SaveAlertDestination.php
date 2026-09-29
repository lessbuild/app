<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AlertDestinationType;
use App\Enums\AuditAction;
use App\Exceptions\StateConflict;
use App\Models\Account;
use App\Models\AlertDestination;
use App\Models\OnCallSchedule;
use App\Models\User;
use App\Services\Monitoring\PublicWebhookTarget;
use App\Services\Monitoring\TelemetryRedactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SaveAlertDestination
{
    /**
     * Create a new SaveAlertDestination instance.
     *
     * Creates or changes an alert destination.
     *
     * @param  PublicWebhookTarget  $targets  Checks a webhook URL points at a public host.
     * @param  TelemetryRedactor  $redactor  Redacts the name.
     * @param  RecordAuditEntry  $audit  Records the change (never the URL or secret).
     */
    public function __construct(private readonly PublicWebhookTarget $targets, private readonly TelemetryRedactor $redactor, private readonly RecordAuditEntry $audit) {}

    /**
     * Create or change where alerts go: an account member's email, a signed webhook, Slack, Teams, Discord or PagerDuty.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  array<string, mixed>  $data
     * @param  AlertDestination|null  $destination
     * @return AlertDestination
     */
    public function handle(Account $account, User $actor, array $data, ?AlertDestination $destination = null): AlertDestination
    {
        return DB::transaction(function () use ($account, $actor, $data, $destination): AlertDestination {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize($destination === null ? 'create' : 'update', $destination ?? [AlertDestination::class, $account]);
            $new = $destination === null;
            if (! $new) {
                $destination = AlertDestination::forAccount($account)->lockForUpdate()->findOrFail($destination->id);
                StateConflict::unlessVersion($destination->state_version, (int) $data['version'], __('This destination changed. Refresh before trying again.'));
            }
            $destination ??= new AlertDestination;
            $type = $new ? AlertDestinationType::from($data['type']) : $destination->type;
            $recipientId = null;
            $scheduleId = null;
            $endpoint = null;
            $secret = null;
            if ($type === AlertDestinationType::Email) {
                $chosen = (string) ($data['recipient_user_id'] ?? '');
                if (str_starts_with($chosen, 'schedule:')) {
                    $scheduleId = OnCallSchedule::query()->where('account_id', $account->id)->whereKey((int) substr($chosen, 9))->value('id');
                } else {
                    $recipientId = $account->members()->whereNotNull('email_verified_at')->whereKey($chosen === '' ? 0 : $chosen)->value('users.id');
                }
                if ($recipientId === null && $scheduleId === null) {
                    throw ValidationException::withMessages(['recipient_user_id' => __('Choose a verified member of this account, or one of its on-call schedules.')]);
                }
            } elseif ($type === AlertDestinationType::PagerDuty) {
                $endpoint = 'https://events.pagerduty.com/v2/enqueue';
                $secret = filled($data['signing_secret'] ?? null) ? $data['signing_secret'] : $destination->signing_secret;
                if (! is_string($secret) || $secret === '') {
                    throw ValidationException::withMessages(['signing_secret' => __('Enter the PagerDuty integration routing key.')]);
                }
            } else {
                $endpoint = $data['endpoint_url'] ?? $destination->endpoint_url;
                if (! is_string($endpoint) || $this->targets->host($endpoint, $type) === null) {
                    throw ValidationException::withMessages(['endpoint_url' => __('Use a valid public HTTPS destination on port 443 for this provider.')]);
                }
            }
            $destination->forceFill([
                'account_id' => $account->id, 'type' => $type,
                'name' => $this->redactor->redact(['name' => $data['name']])['name'],
                'enabled' => (bool) $data['enabled'], 'recipient_user_id' => $recipientId, 'on_call_schedule_id' => $scheduleId, 'endpoint_url' => $endpoint,
            ]);
            if ($type === AlertDestinationType::PagerDuty) {
                $destination->forceFill(['signing_secret' => $secret]);
            } elseif ($type !== AlertDestinationType::Webhook) {
                $destination->forceFill(['signing_secret' => null]);
            }
            if ($new && $type === AlertDestinationType::Webhook) {
                $destination->forceFill(['signing_secret' => Str::random(64)]);
            }
            $changed = $new || $destination->isDirty(['name', 'enabled', 'recipient_user_id', 'on_call_schedule_id', 'endpoint_url', 'signing_secret']);
            if ($new || $destination->isDirty()) {
                $destination->forceFill([
                    'state_version' => $new ? 0 : $destination->state_version + 1,
                    'target_revision' => $new ? 0 : $destination->target_revision + (int) ($changed && $destination->isDirty(['enabled', 'recipient_user_id', 'on_call_schedule_id', 'endpoint_url'])),
                ])->save();
            }

            if ($new || $changed) {
                $this->audit->handle($new ? AuditAction::AlertDestinationCreated : AuditAction::AlertDestinationUpdated, $actor, $account->id, [
                    'destination' => $destination->name, 'type' => $type->value, 'enabled' => $destination->enabled,
                ]);
            }

            return $destination;
        }, attempts: 3);
    }
}
