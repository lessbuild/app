<?php

declare(strict_types=1);

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Contracts\RequestOrigin;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;

final class RecordAuditEntry
{
    public function __construct(private readonly RequestOrigin $origin) {}

    /** @param array<string, scalar|null> $context */
    public function handle(AuditAction $action, ?User $actor, ?string $accountId = null, array $context = []): void
    {
        $userAgent = $this->origin->userAgent();

        $entry = new AuditEntry;
        $entry->forceFill([
            'account_id' => $accountId,
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
