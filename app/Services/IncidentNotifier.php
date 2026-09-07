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
    /**
     * Record an operational failure and notify configured account and webhook destinations.
     *
     * @param  User  $user  The account receiving resource notifications.
     * @param  string  $category  The incident resource category.
     * @param  int  $resourceId  The resource identifier within that category.
     * @param  string  $title  The notification and incident title.
     * @param  string  $message  The diagnostic message recorded with the event.
     * @return void No value; persists incident history and dispatches eligible notifications.
     */
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

    /**
     * Resolve unread failures and the active incident, then send a recovery notification.
     *
     * @param  User  $user  The account receiving resource notifications.
     * @param  string  $category  The incident resource category.
     * @param  int  $resourceId  The resource identifier within that category.
     * @param  string  $title  The notification and incident title.
     * @param  string  $message  The diagnostic message recorded with the event.
     * @return int The number of unread failure notifications marked read.
     */
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

    /**
     * Resolve existing failure state and notify recovery only when something was open.
     *
     * @param  User  $user  The account receiving resource notifications.
     * @param  string  $category  The incident resource category.
     * @param  int  $resourceId  The resource identifier within that category.
     * @param  string  $title  The notification and incident title.
     * @param  string  $message  The diagnostic message recorded with the event.
     * @return int The number of unread failure notifications marked read; an incident alone can also trigger recovery.
     */
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

    /**
     * Mark the user's unread failure notifications for one resource as read.
     *
     * @param  User  $user  The account receiving resource notifications.
     * @param  string  $category  The incident resource category.
     * @param  int  $resourceId  The resource identifier within that category.
     * @return int The number of matching notifications updated.
     */
    private function resolveFailures(User $user, string $category, int $resourceId): int
    {
        return $user->unreadNotifications()
            ->where('data->category', $category)
            ->where('data->resource_id', $resourceId)
            ->where('data->status', NotificationInbox::STATUS_FAILED)
            ->update(['read_at' => now()]);
    }

    /**
     * Deliver a healthy-state notification according to workspace notification preferences.
     *
     * @param  User  $user  The account receiving resource notifications.
     * @param  string  $category  The incident resource category.
     * @param  int  $resourceId  The resource identifier within that category.
     * @param  string  $title  The notification and incident title.
     * @param  string  $message  The diagnostic message recorded with the event.
     * @return void No value; sends the account notification when enabled and queues eligible webhooks.
     */
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

    /**
     * Queue bounded incident payloads for active destinations subscribed to this event.
     *
     * @param  User  $user  The account receiving resource notifications.
     * @param  string  $event  The failure or recovery event used to select destinations.
     * @param  string  $category  The incident resource category.
     * @param  int  $resourceId  The resource identifier within that category.
     * @param  string  $title  The notification and incident title.
     * @param  string  $message  The diagnostic message recorded with the event.
     * @return void No value; returns without dispatching when the resource has no workspace.
     */
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

    /**
     * Resolve a resource's owning workspace for incident preferences and routing.
     *
     * @param  User  $user  The account supplying the fallback workspace for other categories.
     * @param  string  $category  The incident resource category.
     * @param  int  $resourceId  The resource identifier within that category.
     * @return Organization|null The resource workspace, or null when no current owning workspace can be found.
     */
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

    /**
     * Persist a failure, retrying once if another worker creates the active incident first.
     *
     * @param  Organization  $organization  The workspace owning the affected resource.
     * @param  string  $category  The incident resource category.
     * @param  int  $resourceId  The resource identifier within that category.
     * @param  string  $title  The notification and incident title.
     * @param  string  $message  The diagnostic message recorded with the event.
     * @return void No value; appends to the winning incident after a uniqueness race.
     */
    private function recordFailure(Organization $organization, string $category, int $resourceId, string $title, string $message): void
    {
        try {
            $this->persistFailure($organization, $category, $resourceId, $title, $message);
        } catch (UniqueConstraintViolationException) {
            // Another worker opened this resource's incident first; append to it.
            $this->persistFailure($organization, $category, $resourceId, $title, $message);
        }
    }

    /**
     * Create or update a resource's active incident and append its occurrence event atomically.
     *
     * @param  Organization  $organization  The workspace owning the affected resource.
     * @param  string  $category  The incident resource category.
     * @param  int  $resourceId  The resource identifier within that category.
     * @param  string  $title  The notification and incident title.
     * @param  string  $message  The diagnostic message recorded with the event.
     * @return void No value; records a detected or repeated event under a database transaction.
     */
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

    /**
     * Resolve an open or acknowledged resource incident under a row lock.
     *
     * @param  Organization|null  $organization  The owning workspace, or null when none could be resolved.
     * @param  string  $category  The incident resource category.
     * @param  int  $resourceId  The resource identifier within that category.
     * @param  string  $message  The resolution text, bounded to 5,000 characters.
     * @return bool True when an active incident was resolved; false if the workspace or incident is absent.
     */
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
