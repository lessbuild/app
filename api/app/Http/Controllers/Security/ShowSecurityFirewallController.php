<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\Project;
use App\Models\SecurityZone;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Security\ProjectZonesQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowSecurityFirewallController
{
    /**
     * Show the Cloudflare zones behind the project's domains, with their security settings.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectZonesQuery  $zones
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectZonesQuery $zones, Entitlements $entitlements): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'zones' => $zones->handle($project)->map(fn (array $row): array => [
                'id' => $row['zone']->zone_id,
                'name' => $row['zone']->zone_name,
                'domains' => $row['domains'],
                'level' => $row['zone']->security_level,
                'botFightMode' => (bool) $row['zone']->bot_fight_mode,
                'underAttack' => (bool) $row['zone']->under_attack,
                'error' => $row['zone']->last_error,
            ])->values(),
            'levels' => collect(SecurityZone::LEVELS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => __($label)])->values(),
            'included' => $entitlements->for($project->account)->has('security.waf'),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
