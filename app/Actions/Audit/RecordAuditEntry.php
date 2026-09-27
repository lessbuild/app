<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Contracts\RequestOrigin;
use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Str;

final class RecordAuditEntry
{
    public function __construct(private readonly RequestOrigin $origin) {}

    /** @param array<string, scalar|null> $context */
    public function handle(AuditAction $action, ?User $actor, ?string $accountId = null, array $context = [], ?string $projectId = null): void
    {
        $userAgent = $this->origin->userAgent();

        $entry = new AuditEntry;
        $entry->forceFill([
            'account_id' => $accountId,
            'project_id' => $projectId,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'action' => $action,
            'context' => $context === [] ? null : $context,
            'ip_address' => $this->origin->ipAddress(),
            'user_agent' => $userAgent !== null ? Str::limit($userAgent, 500, '') : null,
        ])->save();
    }
}
