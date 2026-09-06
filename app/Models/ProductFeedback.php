<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFeedback extends Model
{
    public const CATEGORIES = ['bug', 'idea', 'usability'];

    public const SEVERITIES = ['low', 'normal', 'high', 'blocking'];

    public const STATUSES = ['open', 'reviewing', 'planned', 'resolved', 'closed'];

    protected $table = 'product_feedback';

    protected $guarded = [];

    protected $casts = [
        'description' => 'encrypted',
        'reproduction_steps' => 'encrypted',
        'review_response' => 'encrypted',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
