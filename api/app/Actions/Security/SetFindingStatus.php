<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\SecurityFinding;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SetFindingStatus
{
    /**
     * Create a new SetFindingStatus instance.
     *
     * @param  RecordAuditEntry  $audit  Records who ignored or reopened it.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Ignore a finding (with a reason, such as a false positive or an accepted risk) or reopen an ignored one. The next
     * scan decides whether a reopened finding is still there.
     *
     * @param  User  $actor
     * @param  SecurityFinding  $finding
     * @param  bool  $ignore
     * @param  string|null  $reason
     * @return void
     */
    public function handle(User $actor, SecurityFinding $finding, bool $ignore, ?string $reason): void
    {
        Gate::forUser($actor)->authorize('manageService', [$finding->project, 'security']);
        $finding->forceFill($ignore
            ? ['status' => 'ignored', 'ignored_reason' => $reason === null ? null : mb_substr(trim($reason), 0, 1000), 'ignored_by' => $actor->id]
            : ['status' => 'open', 'ignored_reason' => null, 'ignored_by' => null])->save();
        $this->audit->handle($ignore ? AuditAction::SecurityFindingIgnored : AuditAction::SecurityFindingReopened, $actor, $finding->project->account_id, ['finding' => $finding->title], $finding->project_id);
    }
}
