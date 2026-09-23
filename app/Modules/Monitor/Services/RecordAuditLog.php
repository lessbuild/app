<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\AuditLog;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class RecordAuditLog
{
    public function __construct(
        private readonly Request $request,
        private readonly TelemetryRedactor $redactor,
        private readonly WorkspacePlanLimits $limits,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function record(Workspace $workspace, ?User $actor, string $action, ?Model $subject = null, array $metadata = []): ?AuditLog
    {
        if (! $this->limits->auditLogEnabled($workspace)) {
            return null;
        }

        $userAgent = $this->request->userAgent();

        return $workspace->auditLogs()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $this->redactor->redact($metadata),
            'ip_address' => $this->request->ip(),
            'user_agent' => is_string($userAgent) ? Str::limit($userAgent, 1024, '') : null,
            'created_at' => now('UTC'),
        ]);
    }
}
