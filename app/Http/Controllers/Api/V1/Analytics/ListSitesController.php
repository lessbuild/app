<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Models\Account;
use App\Models\AnalyticsSite;
use App\Models\User;
use App\Queries\Analytics\AccountSitesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListSitesController
{
    /**
     * List the Analytics sites the token can read (`GET /api/v1/analytics/sites`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  AccountSitesQuery  $sites
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, AccountSitesQuery $sites): JsonResponse
    {
        $account = $request->attributes->get('account');
        abort_unless($account instanceof Account, 403);

        return response()->json(['data' => $sites->handle($account, $user)
            ->filter(fn (AnalyticsSite $site): bool => $user->can('view', $site))
            ->map(fn (AnalyticsSite $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'project_id' => $site->project_id,
                'public_id' => $site->public_id,
                'domains' => $site->domains,
                'timezone' => $site->timezone,
                'verified' => $site->isVerified(),
                'last_event_at' => $site->last_event_at?->toIso8601String(),
            ])->values()]);
    }
}
