<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Exceptions\AccountRuleViolation;
use App\Jobs\Security\PatchServer;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Queries\Security\ProjectServersQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Gate;

final class SetPatchWindow
{
    /**
     * Create a new SetPatchWindow instance.
     *
     * @param  ProjectServersQuery  $servers  Checks the server belongs to the project.
     * @param  Entitlements  $entitlements  Checks the plan includes server hardening.
     */
    public function __construct(private readonly ProjectServersQuery $servers, private readonly Entitlements $entitlements) {}

    /**
     * Set a project server's weekly update window (day and hour in UTC, and whether to reboot when needed), turn it
     * off (no day), or install updates right now.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  int  $serverId
     * @param  int|null  $day  0 (Sunday) to 6, or null for no window
     * @param  int  $hour  0 to 23
     * @param  bool  $reboot
     * @param  bool  $now  install updates now instead of saving the window
     * @return Server
     */
    public function handle(User $actor, Project $project, int $serverId, ?int $day, int $hour, bool $reboot, bool $now = false): Server
    {
        Gate::forUser($actor)->authorize('manageService', [$project, 'security']);
        if (! $this->entitlements->for($project->account)->has('security.servers')) {
            throw new AccountRuleViolation('patch_day', __('Update windows come with the Pro Security plan and above.'));
        }
        $server = $this->servers->handle($project)->firstWhere('id', $serverId) ?? throw new AccountRuleViolation('patch_day', __('That server isn’t part of this project.'));
        if ($now) {
            PatchServer::dispatch($server->id, false)->afterCommit();

            return $server;
        }
        $server->forceFill(['patch_day' => $day, 'patch_hour' => max(0, min(23, $hour)), 'patch_reboot' => $reboot])->save();

        return $server;
    }
}
