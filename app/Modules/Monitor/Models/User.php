<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    protected $connection = 'monitor';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<IssueDigestPreference, $this> */
    public function issueDigestPreferences(): HasMany
    {
        return $this->hasMany(IssueDigestPreference::class);
    }

    /** @return HasMany<IssueDigestDelivery, $this> */
    public function issueDigestDeliveries(): HasMany
    {
        return $this->hasMany(IssueDigestDelivery::class, 'recipient_id');
    }

    /** @return HasMany<UsageAlertDelivery, $this> */
    public function usageAlertDeliveries(): HasMany
    {
        return $this->hasMany(UsageAlertDelivery::class, 'recipient_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
