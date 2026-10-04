<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\Membership;
use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\Server;
use App\Models\ServerSshGrant;
use App\Models\User;
use App\Models\UserSshKey;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Security\ProjectServersQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowSecurityServersController
{
    /**
     * Show the project's servers with their open hardening findings, each one's update window, and who has SSH
     * access with their own keys; and the members who could be given access.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectServersQuery  $servers
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectServersQuery $servers, Entitlements $entitlements): JsonResponse
    {
        $list = $servers->handle($project);
        $findings = SecurityFinding::query()->where('project_id', $project->id)->where('source', 'servers')->where('status', 'open')
            ->whereIn('scope', $list->map(fn (Server $server): string => "server:{$server->id}"))->get()->groupBy('scope');
        $grants = ServerSshGrant::query()->whereIn('server_id', $list->modelKeys())->with('user')->get()->groupBy('server_id');
        $keyed = UserSshKey::query()->whereIn('user_id', Membership::query()->where('account_id', $project->account_id)->select('user_id'))->distinct()->pluck('user_id')->all();

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'servers' => $list->map(function (Server $server) use ($findings, $grants, $keyed): array {
                $open = $findings->get("server:{$server->id}", collect());

                return [
                    'id' => $server->id,
                    'name' => $server->display_name ?: $server->name,
                    'user' => $server->name,
                    'ip' => $server->public_ip,
                    'region' => $server->region,
                    'openFindings' => $open->count(),
                    'serious' => $open->contains(fn (SecurityFinding $finding): bool => in_array($finding->severity, ['critical', 'high'], true)),
                    'patchDay' => $server->patch_day,
                    'patchHour' => $server->patch_hour,
                    'patchReboot' => (bool) $server->patch_reboot,
                    'lastPatchedAt' => $server->last_patched_at?->toIso8601String(),
                    'lastPatchError' => $server->last_patch_error,
                    'grants' => $grants->get($server->id, collect())->map(fn (ServerSshGrant $grant): array => [
                        'id' => $grant->id,
                        'userId' => $grant->user_id,
                        'name' => $grant->user->name,
                        'status' => $grant->status,
                        'error' => $grant->error,
                        'hasKeys' => in_array($grant->user_id, $keyed, true),
                    ])->values(),
                ];
            })->values(),
            'members' => Membership::query()->where('account_id', $project->account_id)->with('user')->get()
                ->sortBy(fn (Membership $membership): string => $membership->user->name)
                ->map(fn (Membership $membership): array => ['id' => $membership->user_id, 'name' => $membership->user->name, 'hasKeys' => in_array($membership->user_id, $keyed, true)])->values(),
            'included' => $entitlements->for($project->account)->has('security.servers'),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
