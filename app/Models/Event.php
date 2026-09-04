<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Event extends Model
{
    use HasFactory;

    public const CATEGORIES = [
        'deployment',
        'website',
        'server',
        'command',
        'provider',
        'recipe',
        'account',
        'general',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'event',
        'category',
        'user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function url(): ?string
    {
        return match (true) {
            $this->parentable instanceof Server => route('servers.show', $this->parentable),
            $this->parentable instanceof Website => route('websites.show', $this->parentable),
            $this->parentable instanceof Build => route('builds.show', $this->parentable),
            $this->parentable instanceof ServerCommandExecution && $this->parentable->server => route('servers.show', $this->parentable->server),
            $this->parentable instanceof Provider => route('providers.show', $this->parentable),
            $this->parentable instanceof Recipe && (int) $this->parentable->user_id === (int) $this->user_id => route('recipes.show', $this->parentable),
            $this->parentable instanceof Recipe && $this->parentable->is_published => route('gallery.show', $this->parentable),
            default => null,
        };
    }
}
