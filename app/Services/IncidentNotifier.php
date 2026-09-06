<?php

namespace App\Services;

use App\Jobs\DeliverAlertWebhookJob;
use App\Models\AlertDestination;
use App\Models\Build;
use App\Models\MetricAlertRule;
use App\Models\OperationalIncident;
use App\Models\Organization;
use App\Models\Provider;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Notifications\FailureNotification;
use App\Notifications\NotificationInbox;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class IncidentNotifier
{
    public function fail(
        User $user,
        string $category,
        int $resourceId,
        string $title,
        string $message,
    ): void {
        $organization = $this->organization($user, $category, $resourceId);
        if ($organization) {
            $this->recordFailure($organization, $category, $resourceId, $title, $message);
        }
        if ($organization?->receivesNotification($category, 'failure') ?? true) {
            $user->notify(new FailureNotification(
                $category,
                $resourceId,
                $title,
                $message,
            ));
        }
        $this->fanOut($user, 'failure', $category, $resourceId, $title, $message);
    }

    public function recover(
        User $user,
        string $category,
        int $resourceId,
        string $title,
        string $message,
    ): int {
        $resolved = $this->resolveFailures($user, $category, $resourceId);

        $this->resolveIncident($this->organization($user, $category, $resourceId), $category, $resourceId, $message);

        $this->recovery($user, $category, $resourceId, $title, $message);

        return $resolved;
    }

    public function recoverIfOpen(
        User $user,
        string $category,
        int $resourceId,
        string $title,
        string $message,
    ): int {
        $resolved = $this->resolveFailures($user, $category, $resourceId);
        $incidentResolved = $this->resolveIncident($this->organization($user, $category, $resourceId), $category, $resourceId, $message);
        if ($resolved > 0 || $incidentResolved) {
            $this->recovery($user, $category, $resourceId, $title, $message);
        }

        return $resolved;
    }

    private function resolveFailures(User $user, string $category, int $resourceId): int
    {
        return $user->unreadNotifications()
            ->where('data->category', $category)
            ->where('data->resource_id', $resourceId)
            ->where('data->status', NotificationInbox::STATUS_FAILED)
            ->update(['read_at' => now()]);
    }

    private function recovery(
        User $user,
        string $category,
        int $resourceId,
        string $title,
        string $message,
    ): void {
        if ($this->organization($user, $category, $resourceId)?->receivesNotification($category, 'recovery') ?? true) {
            $user->notify(new FailureNotification(
                $category,
                $resourceId,
                $title,
                $message,
                NotificationInbox::STATUS_HEALTHY,
            ));
        }
        $this->fanOut($user, 'recovery', $category, $resourceId, $title, $message);
    }

    private function fanOut(User $user, string $event, string $category, int $resourceId, string $title, string $message): void
    {
        $organizationId = $this->organization($user, $category, $resourceId)?->id;
        if (! $organizationId) {
            return;
        }
        $payload = [
            'id' => (string) str()->uuid(),
            'event' => $event,
            'category' => $category,
            'resource_id' => $resourceId,
            'title' => str($title)->limit(255)->toString(),
            'message' => str($message)->limit(2000)->toString(),
            'occurred_at' => now()->toIso8601String(),
        ];
        AlertDestination::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get()
            ->filter(fn ($destination): bool => in_array($event, $destination->events ?? [], true))
            ->each(fn ($destination) => DeliverAlertWebhookJob::dispatch($destination->id, $payload));
    }

    private function organization(User $user, string $category, int $resourceId): ?Organization
    {
        $organizationId = match ($category) {
            'website' => Website::query()->whereKey($resourceId)->value('organization_id'),
            'server' => Server::query()->whereKey($resourceId)->value('organization_id'),
            'provider' => Provider::query()->whereKey($resourceId)->value('organization_id'),
            'deployment' => Build::query()->whereKey($resourceId)->first()?->repository?->organization_id,
            'metric' => MetricAlertRule::query()->whereKey($resourceId)->value('organization_id'),
            'scheduled_task' => ScheduledTask::query()->whereKey($resourceId)->first()?->environment?->project?->organization_id,
            default => $user->current_organization_id,
        };

        return $organizationId ? Organization::query()->find($organizationId) : null;
    }

    private function recordFailure(Organization $organization, string $category, int $resourceId, string $title, string $message): void
    {
        try {
            $this->persistFailure($organization, $category, $resourceId, $title, $message);
        } catch (UniqueConstraintViolationException) {
            // Another worker opened this resource's incident first; append to it.
            $this->persistFailure($organization, $category, $resourceId, $title, $message);
        }
    }

    private function persistFailure(Organization $organization, string $category, int $resourceId, string $title, string $message): void
    {
        DB::transaction(function () use ($organization, $category, $resourceId, $title, $message): void {
            $activeKey = $organization->id.':'.$category.':'.$resourceId;
            $incident = OperationalIncident::query()->where('active_key', $activeKey)
                ->lockForUpdate()
                ->first();
            $eventType = $incident ? 'repeated' : 'detected';
            if ($incident) {
                $incident->update([
                    'title' => str($title)->limit(255)->toString(),
                    'summary' => str($message)->limit(5000)->toString(),
                    'occurrences' => $incident->occurrences + 1,
                    'last_seen_at' => now(),
                ]);
            } else {
                $incident = OperationalIncident::query()->create([
                    'organization_id' => $organization->id,
                    'category' => $category,
                    'resource_id' => $resourceId,
                    'active_key' => $activeKey,
                    'status' => OperationalIncident::STATUS_OPEN,
                    'severity' => $category === 'metric' ? 'minor' : 'major',
                    'title' => str($title)->limit(255)->toString(),
                    'summary' => str($message)->limit(5000)->toString(),
                    'detected_at' => now(),
                    'last_seen_at' => now(),
                ]);
            }
            $incident->events()->create(['type' => $eventType, 'message' => str($message)->limit(5000)->toString(), 'occurred_at' => now()]);
        });
    }

    private function resolveIncident(?Organization $organization, string $category, int $resourceId, string $message): bool
    {
        if (! $organization) {
            return false;
        }

        return DB::transaction(function () use ($organization, $category, $resourceId, $message): bool {
            $incident = OperationalIncident::query()->where('organization_id', $organization->id)
                ->where('category', $category)->where('resource_id', $resourceId)
                ->whereIn('status', [OperationalIncident::STATUS_OPEN, OperationalIncident::STATUS_ACKNOWLEDGED])
                ->lockForUpdate()->first();
            if (! $incident) {
                return false;
            }
            $incident->update(['status' => OperationalIncident::STATUS_RESOLVED, 'active_key' => null, 'resolution' => str($message)->limit(5000)->toString(), 'resolved_at' => now()]);
            $incident->events()->create(['type' => 'recovered', 'message' => str($message)->limit(5000)->toString(), 'occurred_at' => now()]);

            return true;
        });
    }
}
