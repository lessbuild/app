<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\Project;
use App\Models\User;
use App\Queries\Dashboard\AccountActivityQuery;
use App\Queries\Projects\AccountAttentionQuery;
use App\Queries\Projects\AccountProjectsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/app/dashboard?activity=`. */
final class ShowDashboardController
{
    /**
     * Return the current account's projects the person can see, what in them needs attention now, and the team's recent
     * activity (deploys, incidents, changes), optionally only one kind.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  AccountProjectsQuery  $projects
     * @param  AccountActivityQuery  $activity
     * @param  AccountAttentionQuery  $attention
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, AccountProjectsQuery $projects, AccountActivityQuery $activity, AccountAttentionQuery $attention): JsonResponse
    {
        $account = $user->currentAccount;
        $kind = $request->query('activity');
        $kind = is_string($kind) && isset(AccountActivityQuery::KINDS[$kind]) ? $kind : null;

        $cards = $account !== null ? $projects->handle($account, $user) : [];

        return response()->json([
            'account' => $account === null ? null : ['id' => $account->id, 'name' => $account->name],
            'projects' => $cards,
            'attention' => $attention->handle(array_map(fn ($card): string => $card->id, $cards)),
            'activity' => $account !== null ? $activity->handle($account, $kind, 15, $user) : [],
            'activityKind' => $kind,
            'activityKinds' => array_map(fn (string $label): string => __($label), AccountActivityQuery::KINDS),
            'canCreate' => $account !== null && $user->can('create', [Project::class, $account]),
        ]);
    }
}
