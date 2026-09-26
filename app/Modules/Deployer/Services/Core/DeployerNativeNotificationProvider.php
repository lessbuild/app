<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\WorkspaceNativeNotificationProvider;
use App\Core\Data\Notifications\WorkspaceNativeNotificationSnapshot;
use App\Core\Data\Notifications\WorkspaceNotification;
use App\Core\Data\Notifications\WorkspaceNotificationSeverity;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\MetricAlertRule;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\RecipeReport;
use App\Modules\Deployer\Models\ScheduledTask;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Notifications\AccountSecurityNotification;
use App\Modules\Deployer\Notifications\NotificationInbox;
use App\Modules\Deployer\Services\NotificationDestinationResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** Projects recipient-authorized native Deployer inbox rows without changing their delivery or read source. */
final class DeployerNativeNotificationProvider implements WorkspaceNativeNotificationProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly WorkspaceProjectAccess $workspaceAccess,
        private readonly ProductWorkspaceAccess $productWorkspaceAccess,
        private readonly DeployerHistoryAccess $historyAccess,
        private readonly NotificationDestinationResolver $destinations,
    ) {}

    public function forWorkspace(
        PlatformUser $user,
        Workspace $workspace,
        Collection $projects,
        array $products,
        int $limit,
    ): WorkspaceNativeNotificationSnapshot {
        if (! Schema::connection('core')->hasTable('legacy_identity_maps')
            || ! Schema::connection('deployer')->hasTable('users')
            || ! Schema::connection('deployer')->hasTable('notifications')) {
            return new WorkspaceNativeNotificationSnapshot(collect());
        }

        if ($this->workspaceAccess->activeMembership($user, $workspace) === null) {
            return new WorkspaceNativeNotificationSnapshot(collect());
        }

        $sourceIds = $this->identities->sourceIdsFor($user, 'deployer');
        if ($sourceIds === []) {
            return new WorkspaceNativeNotificationSnapshot(collect());
        }

        $limit = max(1, min(100, $limit));
        $orgIds = $this->identities->sourceIdsForCanonical('deployer', 'organization', (string) $workspace->getKey(), 'workspace');
        $hasProductGrant = in_array('deployer', $products, true) && $this->hasWorkspaceGrant($user, $orgIds);
        $projectMap = $this->projectResourceMap($projects);
        $items = collect();

        foreach (User::query()->whereIn('id', $sourceIds)->get() as $sourceUser) {
            foreach ($this->sourceNotifications($sourceUser)
                ->whereIn('type', $this->securityTypes())
                ->latest('created_at')->latest('id')->limit($limit)->get() as $securityNotification) {
                if (! $securityNotification instanceof DatabaseNotification
                    || ! is_array($securityNotification->data)
                    || ! $this->isAccountSecurity($securityNotification, $sourceUser, $securityNotification->data)) {
                    continue;
                }

                $data = $securityNotification->data;
                $id = (string) $securityNotification->getKey();
                $key = hash('sha256', 'deployer-native|'.$id);
                $destination = $this->destinations->one($sourceUser, $securityNotification);
                $items->put($key, new WorkspaceNotification(
                    key: $key,
                    threadKey: 'deployer|account-security',
                    workspaceId: (string) $workspace->getKey(),
                    projectId: null,
                    projectName: null,
                    projectUrl: null,
                    environmentName: null,
                    product: 'deployer',
                    productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
                    severity: WorkspaceNotificationSeverity::Information,
                    title: $this->cleanText(is_string($data['title'] ?? null) ? $data['title'] : null, 180) ?? __('Account security changed'),
                    detail: $this->cleanText(is_string($data['message'] ?? null) ? $data['message'] : null, 500) ?? __('Review recent account security activity.'),
                    occurredAt: $securityNotification->created_at?->toImmutable()->utc() ?? CarbonImmutable::now('UTC'),
                    resultUrl: $destination !== null && $destination['available'] ? $this->configuredProductUrl($destination['url']) : null,
                    read: $securityNotification->read_at !== null,
                    sourceProvider: 'deployer',
                    sourceReference: Crypt::encryptString($id),
                    security: true,
                    sourceCategory: 'account',
                ));
            }

            foreach ($this->contexts($sourceUser, $orgIds) as $context) {
                $query = $this->historyAccess->notifications($this->sourceNotifications($context), $context)
                    ->where(function (Builder $visible) use ($orgIds, $context): void {
                        $visible->where(fn (Builder $notification) => $notification->where('data->category', 'deployment')
                            ->whereIn('data->resource_id', Build::query()->where(function (Builder $build) use ($orgIds): void {
                                $build->whereHas('repository', fn (Builder $repository) => $repository->whereIn('organization_id', $orgIds))
                                    ->orWhereHas('environment.project', fn (Builder $project) => $project->whereIn('organization_id', $orgIds));
                            })->select('builds.id')))
                            ->orWhere(fn (Builder $notification) => $notification->where('data->category', 'server')
                                ->whereIn('data->resource_id', Server::query()->whereIn('organization_id', $orgIds)->select('servers.id')))
                            ->orWhere(fn (Builder $notification) => $notification->where('data->category', 'website')
                                ->whereIn('data->resource_id', Website::query()->whereIn('organization_id', $orgIds)->select('websites.id')))
                            ->orWhere(fn (Builder $notification) => $notification->where('data->category', 'provider')
                                ->whereIn('data->resource_id', Provider::query()->whereIn('organization_id', $orgIds)->select('providers.id')))
                            ->orWhere(fn (Builder $notification) => $notification->where('data->category', 'metric')
                                ->whereIn('data->resource_id', MetricAlertRule::query()->whereIn('organization_id', $orgIds)->select('metric_alert_rules.id')))
                            ->orWhere(fn (Builder $notification) => $notification->where('data->category', 'scheduled_task')
                                ->whereIn('data->resource_id', ScheduledTask::query()->whereHas('environment.project', fn (Builder $project) => $project->whereIn('organization_id', $orgIds))->select('scheduled_tasks.id')))
                            ->orWhere(fn (Builder $notification) => $notification->where('data->category', 'recipe')
                                ->whereIn('data->resource_id', RecipeReport::query()
                                    ->whereHas('recipe', fn (Builder $recipe) => $recipe->where('user_id', $context->getKey()))
                                    ->select('recipe_reports.id')))
                            ->orWhere(fn (Builder $notification) => $notification->where('data->category', 'gallery')
                                ->where(function (Builder $gallery) use ($context): void {
                                    $gallery->whereIn('data->resource_id', Recipe::query()->published()->select('recipes.id'))
                                        ->orWhereIn('data->report_id', RecipeReport::query()->where('user_id', $context->getKey())->select('recipe_reports.id'));
                                }));
                    })
                    ->latest('created_at')->latest('id');

                foreach ($query->limit($limit)->get() as $native) {
                    if (! $native instanceof DatabaseNotification || ! is_array($native->data)) {
                        continue;
                    }

                    $data = $native->data;
                    $security = $this->isAccountSecurity($native, $context, $data);
                    if (! $security && (! $hasProductGrant || ! $this->belongsToWorkspace($context, $native, $data, $orgIds))) {
                        continue;
                    }

                    $destination = $this->destinations->one($context, $native);
                    $resultUrl = $destination !== null && $destination['available']
                        ? $this->configuredProductUrl($destination['url'])
                        : null;
                    $severity = $this->severity($data['status'] ?? null);
                    $title = $this->cleanText(is_string($data['title'] ?? null) ? $data['title'] : null, 180)
                        ?? ($security ? __('Account security changed') : __('Product notification'));
                    $detail = $this->cleanText(is_string($data['message'] ?? null) ? $data['message'] : null, 500)
                        ?? __('Open :product for details.', ['product' => config('platform.products.deployer.label', 'Deployer')]);
                    $project = $this->mappedProject($data, $projectMap);
                    $notificationId = (string) $native->getKey();
                    $key = hash('sha256', 'deployer-native|'.$notificationId);

                    $items->put($key, new WorkspaceNotification(
                        key: $key,
                        threadKey: $security ? 'deployer|account-security' : ($project === null ? 'deployer|'.$workspace->getKey().'|resources' : (string) $project->getKey().'|project'),
                        workspaceId: (string) $workspace->getKey(),
                        projectId: $project === null ? null : (string) $project->getKey(),
                        projectName: $project?->name,
                        projectUrl: $project === null ? null : route('core.projects.show', [$workspace, $project]),
                        environmentName: null,
                        product: 'deployer',
                        productLabel: (string) config('platform.products.deployer.label', __('Deployer')),
                        severity: $severity,
                        title: $title,
                        detail: $detail,
                        occurredAt: $native->created_at?->toImmutable()->utc() ?? CarbonImmutable::now('UTC'),
                        resultUrl: $resultUrl,
                        read: $native->read_at !== null,
                        sourceProvider: 'deployer',
                        sourceReference: Crypt::encryptString($notificationId),
                        security: $security,
                        sourceCategory: is_string($data['category'] ?? null) ? $data['category'] : null,
                    ));
                }
            }
        }

        return new WorkspaceNativeNotificationSnapshot(
            $items->sort(fn (WorkspaceNotification $left, WorkspaceNotification $right): int => $right->occurredAt->getTimestamp() <=> $left->occurredAt->getTimestamp() ?: strcmp($right->key, $left->key))
                ->take($limit)->values(),
        );
    }

    public function setRead(
        PlatformUser $user,
        Workspace $workspace,
        Collection $projects,
        array $products,
        string $sourceReference,
        bool $read,
    ): bool {
        if ($this->workspaceAccess->activeMembership($user, $workspace) === null) {
            return false;
        }

        try {
            $id = Crypt::decryptString($sourceReference);
        } catch (Throwable) {
            return false;
        }

        $sourceIds = $this->identities->sourceIdsFor($user, 'deployer');
        if ($sourceIds === []) {
            return false;
        }

        $orgIds = $this->identities->sourceIdsForCanonical('deployer', 'organization', (string) $workspace->getKey(), 'workspace');
        $hasProductGrant = in_array('deployer', $products, true) && $this->hasWorkspaceGrant($user, $orgIds);

        foreach (User::query()->whereIn('id', $sourceIds)->get() as $sourceUser) {
            $securityNotification = $this->sourceNotifications($sourceUser)
                ->whereKey($id)
                ->whereIn('type', $this->securityTypes())
                ->first();
            if ($securityNotification instanceof DatabaseNotification
                && is_array($securityNotification->data)
                && $this->isAccountSecurity($securityNotification, $sourceUser, $securityNotification->data)) {
                $securityNotification->forceFill(['read_at' => $read ? now() : null])->save();

                return true;
            }

            foreach ($this->contexts($sourceUser, $orgIds) as $context) {
                $native = $this->historyAccess->notifications($this->sourceNotifications($context)->whereKey($id), $context)->first();
                if (! $native instanceof DatabaseNotification || ! is_array($native->data)) {
                    continue;
                }

                $security = $this->isAccountSecurity($native, $context, $native->data);
                if (! $security && (! $hasProductGrant || ! $this->belongsToWorkspace($context, $native, $native->data, $orgIds))) {
                    return false;
                }

                $native->forceFill(['read_at' => $read ? now() : null])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<User> */
    private function contexts(User $user, array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return Organization::query()->whereIn('id', $organizationIds)->get()->map(function (Organization $organization) use ($user): User {
            $context = clone $user;
            $context->setAttribute('current_organization_id', $organization->getKey());
            $context->setRelation('currentOrganization', $organization);

            return $context;
        })->all();
    }

    private function isAccountSecurity(DatabaseNotification $notification, User $user, array $data): bool
    {
        return in_array($notification->type, $this->securityTypes(), true)
            && ($data['category'] ?? null) === 'account'
            && filter_var($data['resource_id'] ?? null, FILTER_VALIDATE_INT) === (int) $user->getKey();
    }

    /** The merger rewrites these documented legacy class and morph values; retain safe reads before that migration runs. */
    private function sourceNotifications(User $user): Builder
    {
        return DatabaseNotification::on($user->getConnectionName())
            ->where('notifiable_id', $user->getKey())
            ->whereIn('notifiable_type', array_values(array_unique([
                $user->getMorphClass(), User::class, 'App\\Models\\User',
            ])));
    }

    /** @return list<string> */
    private function securityTypes(): array
    {
        return [AccountSecurityNotification::class, 'App\\Notifications\\AccountSecurityNotification'];
    }

    /** @param list<string> $organizationIds */
    private function hasWorkspaceGrant(PlatformUser $user, array $organizationIds): bool
    {
        foreach ($organizationIds as $organizationId) {
            if ($this->productWorkspaceAccess->allows($user, 'deployer', 'organization', $organizationId)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $organizationIds */
    private function belongsToWorkspace(User $user, DatabaseNotification $notification, array $data, array $organizationIds): bool
    {
        $category = $data['category'] ?? null;
        $resourceId = filter_var($data['resource_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_string($category) || ! $resourceId) {
            return false;
        }

        if (in_array($category, ['recipe', 'gallery'], true)) {
            return $this->destinations->one($user, $notification)['available'] ?? false;
        }

        $record = match ($category) {
            'deployment' => Build::query()->with(['repository:id,organization_id,user_id', 'environment.project'])->find($resourceId),
            'server' => Server::query()->find($resourceId),
            'website' => Website::query()->find($resourceId),
            'provider' => Provider::query()->find($resourceId),
            'metric' => MetricAlertRule::query()->find($resourceId),
            'scheduled_task' => ScheduledTask::query()->with('environment.project')->find($resourceId),
            default => null,
        };

        if ($record === null) {
            return false;
        }

        if ($category === 'deployment') {
            $repository = $record->repository;
            $project = $record->environment?->project;
            $repositoryOrganization = $repository?->organization_id;
            $environmentOrganization = $project?->organization_id;

            if ($repositoryOrganization !== null
                && $environmentOrganization !== null
                && (string) $repositoryOrganization !== (string) $environmentOrganization) {
                return false;
            }

            $repositoryOwned = $repository !== null
                && ($repositoryOrganization !== null
                    ? in_array((string) $repositoryOrganization, $organizationIds, true)
                    : (string) $repository->user_id === (string) $user->getKey());
            $environmentOwned = $project !== null
                && in_array((string) $environmentOrganization, $organizationIds, true);

            return ($repositoryOwned || $environmentOwned)
                && ($repositoryOrganization === null || in_array((string) $repositoryOrganization, $organizationIds, true))
                && ($environmentOrganization === null || in_array((string) $environmentOrganization, $organizationIds, true));
        }

        if ($category === 'scheduled_task') {
            $environment = $record->environment;
            $project = $environment?->project;

            return $project !== null
                && in_array((string) $project->organization_id, $organizationIds, true)
                && $this->destinations->one($user, $notification)['available'] ?? false;
        }

        if ($category === 'metric') {
            if (! in_array((string) $record->organization_id, $organizationIds, true)) {
                return false;
            }

            return $record->server_id === null
                ? app(DeployerProjectAccess::class)->canAccessWorkspaceResources($user)
                : app(DeployerProjectAccess::class)->servers(Server::query()->whereKey($record->server_id), $user)->exists();
        }

        if (isset($record->organization_id)) {
            return in_array((string) $record->organization_id, $organizationIds, true);
        }

        return isset($record->user_id) && (string) $record->user_id === (string) $user->getKey();
    }

    /** @param Collection<string, list<Project>> $map */
    private function mappedProject(array $data, Collection $map): ?Project
    {
        $id = filter_var($data['resource_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $category = $data['category'] ?? null;
        if ($category === 'metric' && $id !== null) {
            $id = MetricAlertRule::query()->whereKey($id)->value('server_id');
            $category = 'server';
        } elseif ($category === 'scheduled_task' && $id !== null) {
            $id = ScheduledTask::query()->whereKey($id)->first()?->environment?->project_id;
            $category = 'project';
        }
        $type = match ($category) {
            'deployment' => 'build', 'server' => 'server', 'website' => 'website', 'provider' => 'provider', 'project' => 'project',
            default => null,
        };

        return $id !== null && $type !== null ? $map->get($type.'|'.$id)?->first() : null;
    }

    /** @param Collection<int, Project> $projects
     * @return Collection<string, list<Project>>
     */
    private function projectResourceMap(Collection $projects): Collection
    {
        if ($projects->isEmpty()) {
            return collect();
        }

        return ProjectResource::query()
            ->whereIn('project_id', $projects->pluck('id')->all())
            ->where('product', 'deployer')
            ->where('status', 'active')
            ->whereIn('resource_type', ['build', 'server', 'website', 'provider', 'project'])
            ->get(['project_id', 'resource_type', 'resource_id'])
            ->groupBy(fn (ProjectResource $resource): string => $resource->resource_type.'|'.$resource->resource_id)
            ->map(fn (Collection $resources): Collection => $resources
                ->map(fn (ProjectResource $resource): ?Project => $projects->firstWhere('id', (string) $resource->project_id))
                ->filter()
                ->values());
    }

    private function configuredProductUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;
        if (! is_string($path) || ! str_starts_with($path, '/')) {
            return null;
        }

        $base = config('platform.products.deployer.url');
        if (! is_string($base) || ! str_starts_with($base, 'https://')) {
            $host = config('platform.products.deployer.host');
            if (! is_string($host) || $host === '') {
                return null;
            }
            $base = 'https://'.$host;
        }

        return rtrim($base, '/').$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function cleanText(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags($value));

        return filled($clean) ? Str::limit(trim($clean), $limit) : null;
    }

    private function severity(mixed $status): WorkspaceNotificationSeverity
    {
        return match ($status) {
            NotificationInbox::STATUS_FAILED => WorkspaceNotificationSeverity::Critical,
            NotificationInbox::STATUS_HEALTHY => WorkspaceNotificationSeverity::Information,
            default => WorkspaceNotificationSeverity::Information,
        };
    }
}
