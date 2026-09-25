<?php

namespace App\Core\Services;

use App\Core\Data\Notifications\WorkspaceNotification;
use App\Core\Data\Notifications\WorkspaceNotificationFeed;
use App\Core\Data\Notifications\WorkspaceNotificationSeverity;
use App\Core\Data\Projects\ProjectWorkflowRun;
use App\Core\Data\Projects\ProjectWorkflowStep;
use App\Core\Enums\ProjectWorkflowStepState;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceNotificationPreference;
use App\Core\Models\WorkspaceNotificationRead;
use Illuminate\Database\LostConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PDOException;

/** Build a redacted cross-product inbox from each module's authorized workflow summaries. */
final class WorkspaceNotifications
{
    public function __construct(private readonly WorkspaceActivityProviderRegistry $providers) {}

    /**
     * @param  Collection<int, Project>  $projects
     * @param  list<string>  $products
     */
    public function forWorkspace(
        PlatformUser $user,
        Workspace $workspace,
        Collection $projects,
        array $products,
        int $limit = 100,
    ): WorkspaceNotificationFeed {
        if ($projects->isEmpty() || $products === []) {
            return new WorkspaceNotificationFeed(collect(), collect(), 0);
        }

        $resultLimit = max(1, min(100, $limit));
        $notifications = collect();
        $unavailableProducts = collect();
        $projectsById = $projects->keyBy(fn (Project $project): string => (string) $project->getKey());

        foreach (array_unique($products) as $product) {
            $provider = $this->providers->get($product);

            if ($provider === null) {
                continue;
            }

            try {
                $snapshot = $provider->recentForWorkspace($user, $workspace, $projects, $resultLimit);
            } catch (LostConnectionException|PDOException) {
                $unavailableProducts->push((string) config('platform.products.'.$product.'.label', str($product)->headline()));

                continue;
            }

            if (! $snapshot->available) {
                $unavailableProducts->push((string) config('platform.products.'.$product.'.label', str($product)->headline()));

                continue;
            }

            foreach ($snapshot->runs as $run) {
                if (! $run instanceof ProjectWorkflowRun
                    || $run->projectId === null
                    || ! $projectsById->has((string) $run->projectId)) {
                    continue;
                }

                $project = $projectsById->get((string) $run->projectId);

                foreach ($run->steps as $step) {
                    if (! $step instanceof ProjectWorkflowStep || $step->product !== $product) {
                        continue;
                    }

                    $severity = $this->severity($step->state);

                    if ($severity === null) {
                        continue;
                    }

                    $notification = $this->notification($workspace, $project, $run, $step, $product, $severity);
                    $notifications->put($notification->key, $notification);
                }
            }
        }

        $notificationKeys = $notifications->keys()->all();
        $reads = $notificationKeys === []
            ? collect()
            : WorkspaceNotificationRead::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('user_id', $user->getKey())
                ->whereIn('notification_key', $notificationKeys)
                ->get(['notification_key'])
                ->keyBy('notification_key');
        $preferences = WorkspaceNotificationPreference::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->get()
            ->keyBy('scope_key');

        $notifications = $notifications
            ->filter(function (WorkspaceNotification $notification) use ($preferences): bool {
                $projectScope = self::preferenceScopeKey($notification->projectId, $notification->product, $notification->severity);
                $globalScope = self::preferenceScopeKey(null, $notification->product, $notification->severity);
                $preference = $preferences->get($projectScope) ?? $preferences->get($globalScope);

                return $preference === null || $preference->enabled;
            })
            ->map(function (WorkspaceNotification $notification) use ($reads): WorkspaceNotification {
                return new WorkspaceNotification(
                    key: $notification->key,
                    threadKey: $notification->threadKey,
                    workspaceId: $notification->workspaceId,
                    projectId: $notification->projectId,
                    projectName: $notification->projectName,
                    projectUrl: $notification->projectUrl,
                    environmentName: $notification->environmentName,
                    product: $notification->product,
                    productLabel: $notification->productLabel,
                    severity: $notification->severity,
                    title: $notification->title,
                    detail: $notification->detail,
                    occurredAt: $notification->occurredAt,
                    resultUrl: $notification->resultUrl,
                    read: $reads->has($notification->key),
                );
            })
            ->sortByDesc(fn (WorkspaceNotification $notification): int => $notification->occurredAt->getTimestamp())
            ->take($resultLimit)
            ->values();

        return new WorkspaceNotificationFeed(
            notifications: $notifications,
            unavailableProducts: $unavailableProducts->unique()->values(),
            unreadCount: $notifications->where('read', false)->count(),
        );
    }

    public static function preferenceScopeKey(?string $projectId, string $product, WorkspaceNotificationSeverity $severity): string
    {
        return hash('sha256', ($projectId ?? '*').'|'.$product.'|'.$severity->value);
    }

    private function notification(
        Workspace $workspace,
        Project $project,
        ProjectWorkflowRun $run,
        ProjectWorkflowStep $step,
        string $product,
        WorkspaceNotificationSeverity $severity,
    ): WorkspaceNotification {
        $projectId = (string) $run->projectId;
        $sourceKey = $run->key.'|'.$step->product.'|'.($step->deliveryId ?? $step->connectionId ?? $step->title);
        $key = hash('sha256', $sourceKey);
        $threadContext = $step->environmentId === null
            ? 'project'
            : 'environment:'.$step->environmentId;
        $projectName = (string) $project->name;

        return new WorkspaceNotification(
            key: $key,
            threadKey: $projectId.'|'.$threadContext,
            workspaceId: (string) $workspace->getKey(),
            projectId: $projectId,
            projectName: $projectName,
            projectUrl: route('core.projects.show', [$workspace, $project]),
            environmentName: $this->text($step->environmentName),
            product: $product,
            productLabel: $step->productLabel,
            severity: $severity,
            title: $this->text($run->title, 180) ?? __('Product update'),
            detail: $this->text($step->detail, 500) ?? __('Open :product for details.', ['product' => $step->productLabel]),
            occurredAt: $step->completedAt ?? $step->recordedAt ?? $run->recordedAt,
            resultUrl: $this->productUrl($product, $step->resultUrl),
        );
    }

    private function text(?string $value, int $limit = 120): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags($value));

        return filled($clean) ? Str::limit(trim($clean), $limit) : null;
    }

    /** Route-generated product links retain their path/query but never their untrusted host. */
    private function productUrl(string $product, ?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;

        if (! is_string($path) || ! str_starts_with($path, '/')) {
            return null;
        }

        $base = config('platform.products.'.$product.'.url');

        if (! is_string($base) || ! str_starts_with($base, 'https://')) {
            $host = config('platform.products.'.$product.'.host');
            if (! is_string($host) || $host === '') {
                return null;
            }

            $base = 'https://'.$host;
        }

        return rtrim($base, '/').$path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private function severity(ProjectWorkflowStepState $state): ?WorkspaceNotificationSeverity
    {
        return match ($state) {
            ProjectWorkflowStepState::Failed,
            ProjectWorkflowStepState::Blocked => WorkspaceNotificationSeverity::Critical,
            ProjectWorkflowStepState::AwaitingApproval,
            ProjectWorkflowStepState::Unknown => WorkspaceNotificationSeverity::Warning,
            ProjectWorkflowStepState::Succeeded,
            ProjectWorkflowStepState::Delivered => WorkspaceNotificationSeverity::Information,
            default => null,
        };
    }
}
