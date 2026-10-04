<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Project;
use App\Models\SecurityAccessReview;
use App\Models\ServerSshGrant;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Security\ProjectServersQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowSecurityAccessController
{
    /**
     * Show everyone and everything with access (members, API tokens, SSH access to the project's servers) as an
     * access review, with the last ten reviews.
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
        $tokens = ApiToken::query()->where('account_id', $project->account_id)->orderBy('name')->get();
        $owners = User::query()->whereIn('id', $tokens->pluck('tokenable_id'))->pluck('name', 'id');

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'members' => Membership::query()->where('account_id', $project->account_id)->with('user')->get()
                ->sortBy(fn (Membership $membership): string => $membership->user->name)
                ->map(fn (Membership $membership): array => [
                    'id' => $membership->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => $membership->role->label(),
                    'twoFactor' => $membership->user->two_factor_confirmed_at !== null,
                    'projects' => $membership->project_ids === null ? null : count($membership->project_ids),
                    'isYou' => $membership->user_id === $user->id,
                ])->values(),
            'tokens' => $tokens->map(fn (ApiToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'owner' => $owners[$token->tokenable_id] ?? null,
                'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                'expiresAt' => $token->expires_at?->toIso8601String(),
            ])->values(),
            'grants' => ServerSshGrant::query()->whereIn('server_id', $servers->handle($project)->modelKeys())->with(['user', 'server'])->get()
                ->map(fn (ServerSshGrant $grant): array => ['id' => $grant->id, 'name' => $grant->user->name, 'server' => $grant->server->display_name ?: $grant->server->name])->values(),
            'reviews' => SecurityAccessReview::query()->where('project_id', $project->id)->with('reviewer')->latest('id')->limit(10)->get()
                ->map(fn (SecurityAccessReview $review): array => [
                    'id' => $review->id,
                    'at' => $review->created_at?->toIso8601String(),
                    'reviewer' => $review->reviewer?->name,
                    'members' => (int) ($review->summary['members'] ?? 0),
                    'tokens' => (int) ($review->summary['tokens'] ?? 0),
                    'removed' => $review->summary['removed'] ?? [],
                ])->values(),
            'dueAfterDays' => SecurityAccessReview::DUE_AFTER_DAYS,
            'requireTwoFactor' => (bool) $project->account->require_two_factor,
            'included' => $entitlements->for($project->account)->has('security.access_reviews'),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
