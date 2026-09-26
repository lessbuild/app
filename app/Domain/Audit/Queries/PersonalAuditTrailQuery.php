<?php

declare(strict_types=1);

namespace App\Domain\Audit\Queries;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Identity\Models\User;

final class PersonalAuditTrailQuery
{
    /** @return list<array{at: string, action: string, description: string, account_id: string|null, ip_address: string|null}> */
    public function handle(User $user): array
    {
        return array_values(AuditEntry::query()
            ->where('actor_id', $user->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AuditEntry $entry): array => [
                'at' => $entry->created_at->toIso8601String(),
                'action' => $entry->action->value,
                'description' => $entry->action->describe($entry->context ?? []),
                'account_id' => $entry->account_id,
                'ip_address' => $entry->ip_address,
            ])
            ->all());
    }
}
