<?php

declare(strict_types=1);

namespace App\Queries\Audit;

use App\Data\Audit\AuditEntryView;
use App\Models\AuditEntry;
use Carbon\CarbonImmutable;

/** Recent changes inside one project, for its overview. Leaves out IP addresses and devices (those stay in the audit log). */
final class ProjectActivityQuery
{
    /**
     * Get the latest entries of a project's activity for its overview page. Addresses and devices are left out; the
     * full audit log shows them to people allowed to see it.
     *
     * @param  string  $projectId
     * @param  int  $limit
     * @return list<AuditEntryView>
     */
    public function handle(string $projectId, int $limit = 8): array
    {
        return array_values(AuditEntry::query()
            ->where('project_id', $projectId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditEntry $entry): AuditEntryView => new AuditEntryView(
                id: $entry->id,
                actor: $entry->actor_name ?? __('System'),
                actorEmail: null,
                description: $entry->action->describe($entry->context ?? []),
                ipAddress: null,
                device: null,
                at: CarbonImmutable::instance($entry->created_at),
            ))
            ->all());
    }
}
