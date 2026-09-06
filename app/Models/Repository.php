<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\RepositoryScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Repository extends Model
{
    use BelongsToOrganization;
    use HasFactory;
    use RepositoryScopes;
    use SoftDeletes;

    protected $hidden = ['build_commands', 'post_deployment_commands', 'webhook_secret'];

    protected $casts = [
        'build_commands' => 'encrypted',
        'post_deployment_commands' => 'encrypted',
        'webhook_enabled' => 'boolean',
        'webhook_pending' => 'boolean',
        'webhook_last_received_at' => 'datetime',
        'webhook_secret' => 'encrypted',
    ];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class)->withTrashed();
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** @return HasMany<Build, $this> */
    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    /** @return HasOne<Build, $this> */
    public function latestBuild(): HasOne
    {
        return $this->builds()->one()->latestOfMany();
    }

    /**
     * Select the newest successful build even when more recent attempts failed.
     *
     * @return HasOne<Build, $this>
     */
    public function latestSuccessfulBuild(): HasOne
    {
        return $this->builds()->one()->ofMany(['id' => 'max'], function (Builder $query): void {
            $query->where('status', Build::STATUS_SUCCEEDED);
        });
    }

    /** @return HasMany<RepositoryWebhookDelivery, $this> */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(RepositoryWebhookDelivery::class);
    }

    /**
     * Check provider capabilities and website/server readiness, loading only missing relations.
     *
     * @return bool Whether this repository can currently be deployed.
     */
    public function isDeploymentReady(): bool
    {
        $this->loadMissing(['provider', 'website.server']);

        return $this->provider?->isSourceControl() === true
            && $this->provider->supportsRepositoryUrl($this->url)
            && $this->website?->trashed() === false
            && $this->website?->provisioning_status === Website::STATUS_ACTIVE
            && $this->website?->server?->provisioning_status === Server::STATUS_ACTIVE;
    }

    /**
     * Build the source provider's commit URL only for a complete hexadecimal revision.
     *
     * @param  ?string  $revision  The full Git revision to link.
     * @return ?string The provider commit URL, or null for absent or invalid revisions.
     */
    public function revisionUrl(?string $revision): ?string
    {
        if (! is_string($revision) || ! preg_match('/\A[0-9a-f]{40,64}\z/D', $revision)) {
            return null;
        }

        $path = preg_replace('/\.git\z/i', '', $this->url);
        $segment = $this->provider?->provider === Provider::TYPE_BITBUCKET ? 'commits' : 'commit';

        return "https://{$path}/{$segment}/{$revision}";
    }
}
