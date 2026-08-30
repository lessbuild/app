<?php

namespace App\Models;

use App\Models\Presenters\WebsitePresenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Website extends Model
{
    use HasFactory;
    use WebsitePresenter;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'websites';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    protected $hidden = ['database_password'];

    protected $casts = [
        'database_password' => 'encrypted',
        'provisioned_at' => 'datetime',
        'setup_stage' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Website $website): void {
            if ($website->deployment_slug) {
                return;
            }

            $base = Str::slug($website->getRawOriginal('name') ?: $website->name);
            $base = substr($base ?: 'website', 0, 32);
            $slug = $base;
            $suffix = 2;

            while (static::query()
                ->where('user_id', $website->user_id)
                ->where('deployment_slug', $slug)
                ->exists()) {
                $ending = '-'.$suffix++;
                $slug = substr($base, 0, 32 - strlen($ending)).$ending;
            }

            $website->deployment_slug = $slug;
        });
    }

    public function databaseIdentifier(): string
    {
        return str_replace('-', '_', $this->deployment_slug);
    }

    public function user(): BelongsTo
    {
        return $this->BelongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }
}
