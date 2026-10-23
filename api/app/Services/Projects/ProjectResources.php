<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Enums\AccountRole;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Repository;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;

/**
 * Keeps members who can only see some of an account's projects inside those projects when they use websites and
 * servers, which belong to the account. A website is a project's when it serves one of the project's environments
 * or one of the project's repositories deploys to it; a server is a project's when it hosts one of those websites.
 * Owners, administrators and members who see every project aren't limited.
 */
final class ProjectResources
{
    /**
     * What's been worked out this request: memberships' limits and projects' websites and servers.
     *
     * @var array<string, mixed>
     */
    private array $known = [];

    /**
     * The projects a person is limited to in an account, or null when they see every project there.
     *
     * @param  User  $user
     * @param  string  $accountId
     * @return list<string>|null
     */
    public function limitedTo(User $user, string $accountId): ?array
    {
        $key = "limit:{$user->id}:{$accountId}";
        if (! array_key_exists($key, $this->known)) {
            $this->known[$key] = $this->readLimit($user, $accountId);
        }

        return $this->known[$key];
    }

    /**
     * Read a person's project limit in an account from their membership.
     *
     * @param  User  $user
     * @param  string  $accountId
     * @return list<string>|null
     */
    private function readLimit(User $user, string $accountId): ?array
    {
        $membership = Membership::query()->where('account_id', $accountId)->where('user_id', $user->id)->first();
        if ($membership === null) {
            return [];
        }

        return in_array($membership->role, [AccountRole::Owner, AccountRole::Admin], true) || $membership->project_ids === null ? null : $membership->project_ids;
    }

    /**
     * The websites of the given projects.
     *
     * @param  list<string>  $projectIds
     * @return list<int>
     */
    public function websiteIds(array $projectIds): array
    {
        sort($projectIds);

        return $this->known['websites:'.implode(',', $projectIds)] ??= $this->readWebsiteIds($projectIds);
    }

    /**
     * Read the websites of the given projects.
     *
     * @param  list<string>  $projectIds
     * @return list<int>
     */
    private function readWebsiteIds(array $projectIds): array
    {
        $environments = Environment::query()->whereIn('project_id', $projectIds)->pluck('id');

        return array_values(array_unique(array_map(intval(...), [
            ...Website::query()->whereIn('environment_id', $environments)->pluck('id')->all(),
            ...Repository::query()->whereIn('project_id', $projectIds)->pluck('website_id')->all(),
        ])));
    }

    /**
     * The servers of the given projects: the ones their websites run on.
     *
     * @param  list<string>  $projectIds
     * @return list<int>
     */
    public function serverIds(array $projectIds): array
    {
        return array_values(array_unique(array_map(intval(...), Website::query()->whereKey($this->websiteIds($projectIds))->pluck('server_id')->filter()->all())));
    }

    /**
     * Whether a person may use a website: anyone who sees every project in its account, or someone limited to
     * projects when it's one of theirs. When a project is given, it must also be that project's.
     *
     * @param  User  $user
     * @param  Website  $website
     * @param  string|null  $projectId  the project the person is working in
     * @return bool
     */
    public function mayUseWebsite(User $user, Website $website, ?string $projectId = null): bool
    {
        $limited = $this->limitedTo($user, $website->account_id);
        if ($limited === null) {
            return true;
        }
        $projects = $projectId === null ? $limited : array_values(array_intersect($limited, [$projectId]));

        return $projects !== [] && in_array($website->id, $this->websiteIds($projects), true);
    }

    /**
     * Whether a person may use a server: anyone who sees every project in its account, or someone limited to
     * projects when one of their projects' websites runs on it (in the given project, when one is given).
     *
     * @param  User  $user
     * @param  Server  $server
     * @param  string|null  $projectId  the project the person is working in
     * @return bool
     */
    public function mayUseServer(User $user, Server $server, ?string $projectId = null): bool
    {
        $limited = $this->limitedTo($user, $server->account_id);
        if ($limited === null) {
            return true;
        }
        $projects = $projectId === null ? $limited : array_values(array_intersect($limited, [$projectId]));

        return $projects !== [] && in_array($server->id, $this->serverIds($projects), true);
    }
}
