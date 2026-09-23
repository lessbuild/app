<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Analytics\Enums\WorkspaceRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Workspace extends AnalyticsModel
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    protected static function booted(): void
    {
        static::creating(function (self $workspace): void {
            $workspace->slug ??= Str::slug($workspace->name).'-'.Str::lower(Str::random(5));
        });
    }

    /** @return BelongsToMany<User> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Site> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return HasMany<Invitation> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function roleFor(int|string $productUserId): ?WorkspaceRole
    {
        $role = data_get($this->users()->whereKey($productUserId)->first(), 'pivot.role');

        return $role ? WorkspaceRole::tryFrom($role) : null;
    }
}
