<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\ProjectPolicy;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Changed only through the Projects actions; nothing is mass-assignable.
 *
 * @property string $id
 * @property string $account_id
 * @property bool $is_sample made-up data to look around with, created from the dashboard
 * @property string|null $created_by_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Carbon|null $checklist_dismissed_at
 * @property string|null $workflow_document the last Deploy workflow (version 1 YAML) applied
 * @property Carbon|null $created_at
 * @property int|null $legacy_id Deployer's numeric ID, which the Deployer API v1 still accepts
 * @property-read Account $account
 */
#[UseFactory(ProjectFactory::class)]
#[UsePolicy(ProjectPolicy::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['checklist_dismissed_at' => 'datetime', 'is_sample' => 'boolean'];
    }

    /**
     * Get the account the project belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the configuration documents reviewed for the project.
     *
     * @return HasMany<ConfigurationReview, $this>
     */
    public function configurationReviews(): HasMany
    {
        return $this->hasMany(ConfigurationReview::class);
    }

    /**
     * Get the project's environments.
     *
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /**
     * Get the project's domains.
     *
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * Get the analytics sites in the project.
     *
     * @return HasMany<AnalyticsSite, $this>
     */
    public function analyticsSites(): HasMany
    {
        return $this->hasMany(AnalyticsSite::class);
    }

    /**
     * Get the releases seen in the project's telemetry.
     *
     * @return HasMany<Release, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /**
     * Get the project's Audit sites.
     *
     * @return HasMany<SiteAudit, $this>
     */
    public function siteAudits(): HasMany
    {
        return $this->hasMany(SiteAudit::class);
    }

    /**
     * Get the runs of the project's audits.
     *
     * @return HasMany<SiteAuditRun, $this>
     */
    public function siteAuditRuns(): HasMany
    {
        return $this->hasMany(SiteAuditRun::class);
    }

    /**
     * Get the services turned on in the project.
     *
     * @return HasMany<EnabledService, $this>
     */
    public function enabledServices(): HasMany
    {
        return $this->hasMany(EnabledService::class);
    }

    /**
     * Determine whether a service is turned on in the project.
     *
     * @param  string  $service
     * @return bool
     */
    public function hasService(string $service): bool
    {
        return $this->enabledServices()->where('service', $service)->exists();
    }
}
