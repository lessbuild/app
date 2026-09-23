<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Analytics\Database\AnalyticsModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends AnalyticsModel
{
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'site_id', 'requested_by', 'token_hash', 'status', 'filters',
        'file_path', 'expires_at', 'completed_at', 'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Site> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
