<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Recipe extends Model
{
    use HasFactory;

    protected $hidden = ['script'];

    protected $casts = ['script' => 'encrypted'];

    protected $fillable = [
        'name',
        'description',
        'script',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class)
            ->withPivot('position')
            ->orderByPivot('position');
    }
}
