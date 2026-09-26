<?php

declare(strict_types=1);

namespace App\Domain\Audit\Queries;

use App\Domain\Audit\Data\AuditEntryView;
use App\Domain\Audit\Models\AuditEntry;
use Carbon\CarbonImmutable;

/** Recent changes inside one project, for its overview. Leaves out IP addresses and devices (those stay in the audit log). */
final class ProjectActivityQuery
{
    /** @return list<AuditEntryView> */
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
