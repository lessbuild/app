<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Exceptions\AccountRuleViolation;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Security\EvidencePack;
use Illuminate\Support\Facades\Gate;

final class ExportEvidencePack
{
    /**
     * Create a new ExportEvidencePack instance.
     *
     * @param  EvidencePack  $pack  Builds the ZIP.
     * @param  Entitlements  $entitlements  Checks the plan includes compliance reports.
     * @param  RecordAuditEntry  $audit  Records who exported it.
     */
    public function __construct(private readonly EvidencePack $pack, private readonly Entitlements $entitlements, private readonly RecordAuditEntry $audit) {}

    /**
     * Build the evidence pack for the last few months and return the temporary file's path.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  int  $months  3, 6 or 12
     * @return string
     */
    public function handle(User $actor, Project $project, int $months): string
    {
        Gate::forUser($actor)->authorize('manageService', [$project, 'security']);
        if (! $this->entitlements->for($project->account)->has('security.compliance')) {
            throw new AccountRuleViolation('months', __('Compliance reports come with the Team Security plan.'));
        }
        $months = in_array($months, [3, 6, 12], true) ? $months : 12;
        $this->audit->handle(AuditAction::EvidenceExported, $actor, $project->account_id, ['months' => $months], $project->id);

        return $this->pack->build($project, now()->toImmutable()->subMonthsNoOverflow($months)->startOfDay(), now()->toImmutable());
    }
}
