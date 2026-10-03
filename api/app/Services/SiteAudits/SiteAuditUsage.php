<?php

declare(strict_types=1);

namespace App\Services\SiteAudits;

use App\Enums\SiteAuditStatus;
use App\Models\Project;
use App\Models\SiteAuditRun;

/** Counts the audits an account has run this month, against its plan's allowance. */
final class SiteAuditUsage
{
    /**
     * The meter audit runs are counted on.
     *
     * @var string
     */
    public const METER = 'audit.runs';

    /**
     * Count the account's audit runs this month that count towards its allowance: every run except failed ones.
     *
     * @param  string  $accountId
     * @return int
     */
    public function usedThisMonth(string $accountId): int
    {
        return SiteAuditRun::query()->whereIn('project_id', Project::query()->where('account_id', $accountId)->select('id'))
            ->where('created_at', '>=', now()->utc()->startOfMonth())->where('status', '!=', SiteAuditStatus::Failed->value)->count();
    }
}
