<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\WorkspaceInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['email', 'role', 'token_hash', 'expires_at', 'accepted_at'])]
#[Hidden(['token_hash'])]
class WorkspaceInvitation extends Model
{
    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory;

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime'];
    }
}
