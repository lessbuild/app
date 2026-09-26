<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceFeedback extends CoreModel
{
    public const CATEGORIES = ['bug', 'idea', 'usability'];

    public const SEVERITIES = ['low', 'normal', 'high', 'blocking'];

    public const STATUSES = ['open', 'reviewing', 'planned', 'resolved', 'closed'];

    protected $fillable = [
        'workspace_id',
        'user_id',
        'reviewed_by_user_id',
        'product',
        'category',
        'severity',
        'status',
        'title',
        'description',
        'reproduction_steps',
        'review_response',
        'page',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'description' => 'encrypted',
            'reproduction_steps' => 'encrypted',
            'review_response' => 'encrypted',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'user_id');
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'reviewed_by_user_id');
    }
}
