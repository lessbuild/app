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
use Throwable;

/** Build a redacted cross-product inbox from each module's authorized workflow summaries. */
final class WorkspaceNotifications
{
    public function __construct(
        private readonly WorkspaceActivityProviderRegistry $providers,
        private readonly WorkspaceNativeNotificationProviderRegistry $nativeProviders,
    ) {}

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
        $resultLimit = max(1, min(100, $limit));
        $notifications = collect();
        $unavailableProducts = collect();
        $projectsById = $projects->keyBy(fn (Project $project): string => (string) $project->getKey());

        foreach ($this->nativeProviders->all() as $product => $provider) {
            try {
                $snapshot = $provider->forWorkspace($user, $workspace, $projects, $products, $resultLimit);
            } catch (Throwable) {
                $unavailableProducts->push((string) config('platform.products.'.$product.'.label', str($product)->headline()));

                continue;
            }

            if (! $snapshot->available) {
                $unavailableProducts->push((string) config('platform.products.'.$product.'.label', str($product)->headline()));

                continue;
            }

            foreach ($snapshot->notifications as $notification) {
                if (! $notification instanceof WorkspaceNotification
                    || $notification->sourceProvider !== $product
                    || $notification->product !== $product
                    || $notification->sourceReference === null
                    || preg_match('/\A[a-f0-9]{64}\z/', $notification->key) !== 1
                    || (string) $notification->workspaceId !== (string) $workspace->getKey()) {
                    continue;
                }

                $security = $product === 'deployer'
                    && $notification->security
                    && $notification->sourceCategory === 'account'
                    && $notification->projectId === null;
                if (! $security && ! in_array($product, $products, true)) {
                    continue;
                }

                $project = $notification->projectId === null
                    ? null
                    : $projectsById->get((string) $notification->projectId);
                if ($notification->projectId !== null
                    && (! $project instanceof Project || (string) $project->workspace_id !== (string) $workspace->getKey())) {
                    continue;
                }

                $notifications->put($notification->key, new WorkspaceNotification(
                    key: $notification->key,
                    threadKey: $notification->threadKey,
                    workspaceId: (string) $workspace->getKey(),
                    projectId: $project === null ? null : (string) $project->getKey(),
                    projectName: $project?->name,
                    projectUrl: $project === null ? null : route('core.projects.show', [$workspace, $project]),
                    environmentName: $this->text($notification->environmentName),
                    product: $product,
                    productLabel: (string) config('platform.products.'.$product.'.label', str($product)->headline()),
                    severity: $notification->severity,
                    title: $this->text($notification->title, 180) ?? __('Product update'),
                    detail: $this->text($notification->detail, 500) ?? __('Open :product for details.', ['product' => config('platform.products.'.$product.'.label', str($product)->headline())]),
                    occurredAt: $notification->occurredAt,
                    resultUrl: $this->productUrl($product, $notification->resultUrl),
                    read: $notification->read,
                    sourceProvider: $product,
                    sourceReference: $notification->sourceReference,
                    security: $security,
                    sourceCategory: $notification->sourceCategory,
                ));
            }
        }

        foreach (array_unique($products) as $product) {
            if ($projects->isEmpty()) {
                continue;
            }

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

        $workflowKeys = $notifications
            ->filter(fn (WorkspaceNotification $notification): bool => $notification->sourceProvider === null)
            ->keys()
            ->all();
        $reads = $workflowKeys === []
            ? collect()
            : WorkspaceNotificationRead::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('user_id', $user->getKey())
                ->whereIn('notification_key', $workflowKeys)
                ->get(['notification_key'])
                ->keyBy('notification_key');
        $preferences = WorkspaceNotificationPreference::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->get()
            ->keyBy('scope_key');

        $notifications = $notifications
            ->filter(function (WorkspaceNotification $notification) use ($preferences): bool {
                if ($notification->security) {
                    return true;
                }
                $projectScope = self::preferenceScopeKey($notification->projectId, $notification->product, $notification->severity);
                $globalScope = self::preferenceScopeKey(null, $notification->product, $notification->severity);
                $preference = $preferences->get($projectScope) ?? $preferences->get($globalScope);

                return $preference === null || $preference->enabled;
            })
            ->map(function (WorkspaceNotification $notification) use ($reads): WorkspaceNotification {
                $read = $notification->sourceProvider !== null
                    ? $notification->read
                    : $reads->has($notification->key);

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
                    read: $read,
                    sourceProvider: $notification->sourceProvider,
                    sourceReference: $notification->sourceReference,
                    security: $notification->security,
                    sourceCategory: $notification->sourceCategory,
                );
            })
            ->sort(fn (WorkspaceNotification $left, WorkspaceNotification $right): int => $right->occurredAt->getTimestamp() <=> $left->occurredAt->getTimestamp()
                    ?: strcmp($right->key, $left->key))
            ->take($resultLimit)
            ->values();

        return new WorkspaceNotificationFeed(
            notifications: $notifications,
            unavailableProducts: $unavailableProducts->unique()->values(),
            unreadCount: $notifications->where('read', false)->count(),
        );
    }

    public function setRead(
        PlatformUser $user,
        Workspace $workspace,
        Collection $projects,
        array $products,
        WorkspaceNotification $notification,
        bool $read,
    ): bool {
        if ($notification->sourceProvider === null || $notification->sourceReference === null) {
            return false;
        }

        $provider = $this->nativeProviders->get($notification->sourceProvider);

        if ($provider === null) {
            return false;
        }

        try {
            return $provider->setRead(
                $user,
                $workspace,
                $projects,
                $products,
                $notification->sourceReference,
                $read,
            );
        } catch (Throwable) {
            return false;
        }
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
