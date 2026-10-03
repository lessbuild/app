<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Exceptions\AccountRuleViolation;
use App\Models\Build;
use App\Models\MigrationApproval;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ApproveMigrations
{
    /**
     * Create a new ApproveMigrations instance.
     *
     * @param  RedeployBuild  $redeploy  Deploys the revision again.
     * @param  RecordAuditEntry  $audit  Records who approved.
     */
    public function __construct(private readonly RedeployBuild $redeploy, private readonly RecordAuditEntry $audit) {}

    /**
     * Approve the destructive migrations a deploy stopped for, and deploy the same revision again so they run. Only
     * people who can approve deploys can.
     *
     * @param  User  $actor
     * @param  Build  $build
     * @return Build the new deploy
     */
    public function handle(User $actor, Build $build): Build
    {
        Gate::forUser($actor)->authorize('approve', $build);
        if ($build->destructive_migrations === null || $build->environment_id === null || $build->revision === null) {
            throw new AccountRuleViolation('build', __('This deploy didn’t stop for migrations.'));
        }
        $approval = MigrationApproval::query()->where('environment_id', $build->environment_id)->where('revision', $build->revision)->first() ?? new MigrationApproval;
        $approval->forceFill(['environment_id' => $build->environment_id, 'revision' => $build->revision, 'approved_by' => $actor->id, 'statements' => $build->destructive_migrations])->save();
        $this->audit->handle(AuditAction::MigrationsApproved, $actor, $build->repository->project->account_id, ['build' => $build->id, 'revision' => $build->revision]);

        return $this->redeploy->handle($actor, $build);
    }
}
