<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

#[Fillable(['workspace_id', 'actor_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address', 'user_agent', 'created_at'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class AuditLog extends Model
{
    public const ACTION_LABELS = [
        'workspace.updated' => 'Workspace updated',
        'member.role_updated' => 'Member role updated',
        'member.removed' => 'Member removed',
        'invitation.sent' => 'Invitation sent',
        'invitation.revoked' => 'Invitation revoked',
        'invitation.accepted' => 'Invitation accepted',
        'ingest_token.created' => 'Ingestion token created',
        'ingest_token.rotated' => 'Ingestion token rotated',
        'ingest_token.revoked' => 'Ingestion token revoked',
        'alert_destination.created' => 'Alert destination created',
        'alert_destination.updated' => 'Alert destination updated',
        'alert_destination.rotated' => 'Alert destination signing key rotated',
        'alert_destination.archived' => 'Alert destination archived',
        'alert_rule.created' => 'Alert rule created',
        'alert_rule.updated' => 'Alert rule updated',
        'alert_rule.archived' => 'Alert rule archived',
        'alert_routing.updated' => 'Alert routing updated',
        'alert_escalations.updated' => 'Alert escalation policy updated',
        'billing.checkout_started' => 'Billing checkout started',
        'monitor.created' => 'Monitor created',
        'monitor.updated' => 'Monitor updated',
        'monitor.archived' => 'Monitor archived',
    ];

    public const UPDATED_AT = null;

    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /** @param Builder<AuditLog> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereBelongsTo($workspace);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function label(): string
    {
        return self::ACTION_LABELS[$this->action] ?? Str::headline($this->action);
    }

    public function subjectLabel(): string
    {
        $label = $this->metadata['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : 'Workspace configuration';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
