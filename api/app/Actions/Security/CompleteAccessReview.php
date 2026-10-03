<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Accounts\RemoveMember;
use App\Actions\ApiTokens\RevokeApiToken;
use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Exceptions\AccountRuleViolation;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Project;
use App\Models\SecurityAccessReview;
use App\Models\ServerSshGrant;
use App\Models\User;
use App\Queries\Security\ProjectServersQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Gate;

final class CompleteAccessReview
{
    /**
     * Create a new CompleteAccessReview instance.
     *
     * @param  RemoveMember  $removeMember  Removes members the reviewer said should go.
     * @param  RevokeApiToken  $revokeToken  Revokes tokens.
     * @param  RevokeSshAccess  $revokeSsh  Removes SSH access.
     * @param  ProjectServersQuery  $servers  Finds the project's servers.
     * @param  Entitlements  $entitlements  Checks the plan includes reviews.
     * @param  QueueSecurityScan  $scan  Re-checks access afterwards.
     * @param  RecordAuditEntry  $audit  Records the review.
     */
    public function __construct(
        private readonly RemoveMember $removeMember,
        private readonly RevokeApiToken $revokeToken,
        private readonly RevokeSshAccess $revokeSsh,
        private readonly ProjectServersQuery $servers,
        private readonly Entitlements $entitlements,
        private readonly QueueSecurityScan $scan,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * Complete an access review: remove the members, revoke the API tokens and take away the SSH access the reviewer
     * marked, then record what was reviewed and removed, as evidence for audits.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  list<string>  $memberIds  memberships to remove
     * @param  list<int>  $tokenIds  API tokens to revoke
     * @param  list<int>  $grantIds  SSH grants to remove
     * @return SecurityAccessReview
     */
    public function handle(User $actor, Project $project, array $memberIds, array $tokenIds, array $grantIds): SecurityAccessReview
    {
        Gate::forUser($actor)->authorize('manageService', [$project, 'security']);
        if (! $this->entitlements->for($project->account)->has('security.access_reviews')) {
            throw new AccountRuleViolation('review', __('Access reviews come with the Team Security plan.'));
        }
        $removed = [];
        foreach (Membership::query()->where('account_id', $project->account_id)->whereIn('id', $memberIds)->with(['user', 'account'])->get() as $membership) {
            $this->removeMember->handle($actor, $membership);
            $removed[] = (string) __('Member :name', ['name' => $membership->user->name]);
        }
        foreach (ApiToken::query()->where('account_id', $project->account_id)->whereIn('id', $tokenIds)->with('account')->get() as $token) {
            $this->revokeToken->handle($actor, $token);
            $removed[] = (string) __('API token :name', ['name' => $token->name]);
        }
        foreach (ServerSshGrant::query()->whereIn('server_id', $this->servers->handle($project)->modelKeys())->whereIn('id', $grantIds)->with(['user', 'server'])->get() as $grant) {
            $this->revokeSsh->handle($actor, $project, $grant);
            $removed[] = (string) __('SSH access for :name on :server', ['name' => $grant->user->name, 'server' => $grant->server->name]);
        }
        $review = new SecurityAccessReview;
        $review->forceFill([
            'account_id' => $project->account_id, 'project_id' => $project->id, 'reviewed_by' => $actor->id,
            'summary' => [
                'members' => Membership::query()->where('account_id', $project->account_id)->count() + count($memberIds),
                'tokens' => ApiToken::query()->where('account_id', $project->account_id)->count() + count($tokenIds),
                'grants' => ServerSshGrant::query()->whereIn('server_id', $this->servers->handle($project)->modelKeys())->count(),
                'removed' => $removed,
            ],
        ])->save();
        $this->audit->handle(AuditAction::AccessReviewCompleted, $actor, $project->account_id, ['removed' => count($removed)], $project->id);
        $this->scan->handle($project, 'access');

        return $review;
    }
}
