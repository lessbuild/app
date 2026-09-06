<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeReport extends Model
{
    public const REASONS = [
        'security',
        'broken',
        'misleading',
        'outdated',
        'other',
    ];

    protected $fillable = ['recipe_id', 'reason', 'details', 'resolved_at', 'resolution_note'];

    protected $hidden = ['details', 'resolution_note'];

    protected $casts = [
        'details' => 'encrypted',
        'resolution_note' => 'encrypted',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
