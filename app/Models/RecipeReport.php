<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeReport extends Model
{
    public const REASONS = [
        'security',
        'broken',
        'outdated',
        'misleading',
        'other',
    ];

    protected $fillable = ['recipe_id', 'reason', 'details'];

    protected $hidden = ['details'];

    protected $casts = ['details' => 'encrypted'];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
