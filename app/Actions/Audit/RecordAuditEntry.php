<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Contracts\RequestOrigin;
use App\Enums\AuditAction;
use App\Jobs\Audit\StreamAuditEntry;
use App\Models\AuditEntry;
use App\Models\AuditStream;
use App\Models\User;
use Illuminate\Support\Str;

final class RecordAuditEntry
{
    /**
     * Create a new RecordAuditEntry instance.
     *
     * Writes audit entries.
     *
     * @param  RequestOrigin  $origin  Where the current request came from.
     */
    public function __construct(private readonly RequestOrigin $origin) {}

    /**
     * Store an entry with the actor's name and email copied in (so it still reads after they leave), the request's IP
     * and user agent, and the account and project it belongs to. Entries without an account are the person's own
     * security log. Accounts with audit streams get the entry sent to them in the background.
     *
     * @param  AuditAction  $action
     * @param  User|null  $actor
     * @param  string|null  $accountId
     * @param  array<string, scalar|null>  $context
     * @param  string|null  $projectId
     * @return void
     */
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
        if ($accountId !== null && AuditStream::query()->where('account_id', $accountId)->where('enabled', true)->exists()) {
            StreamAuditEntry::dispatch($entry->id)->afterCommit();
        }
    }
}
