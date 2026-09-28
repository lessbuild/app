<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Deploy\RepositoryPath;
use Carbon\CarbonImmutable;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A Git repository in a project that deploys to a website, cloned over HTTPS with its Git provider's token. Its webhook
 * (`/api/repositories/{id}/webhook`, signed with `webhook_secret`) deploys pushes to the branch.
 *
 * @property int $id
 * @property string $project_id
 * @property string|null $created_by
 * @property int|null $provider_id
 * @property int $website_id
 * @property string|null $environment_id
 * @property string $name
 * @property string $url `host/owner/name` without a scheme
 * @property string $branch
 * @property string|null $deployment_root a subdirectory to deploy, when not the repository root
 * @property string|null $build_commands
 * @property string|null $post_deployment_commands
 * @property list<string>|null $auto_deploy_include_paths
 * @property list<string>|null $auto_deploy_exclude_paths
 * @property string|null $webhook_secret
 * @property bool $webhook_enabled
 * @property CarbonImmutable|null $webhook_last_received_at
 * @property bool $webhook_pending a push arrived while a deploy was running; it deploys when that finishes
 * @property string|null $webhook_pending_revision
 * @property string|null $webhook_pending_commit_message
 * @property bool $previews_enabled pull requests into the branch get their own preview
 * @property string|null $preview_domain previews are served at `pr-{number}-{project}.{domain}`
 * @property int $preview_ttl_hours a preview closes this long after its last activity
 * @property string|null $preview_initialization_command runs once on each preview, after its first deploy
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Project $project
 * @property-read Provider|null $provider
 * @property-read Website $website
 * @property-read Environment|null $environment
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Build> $builds
 * @property-read Preview|null $preview the preview this repository deploys, when it's a preview's own repository
 */
#[Hidden(['webhook_secret'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(RepositoryFactory::class)]
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the project the repository belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the Git provider it's cloned through.
     *
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * Get the website it deploys to, including deleted ones.
     *
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class)->withTrashed();
    }

    /**
     * Get the environment its deploys are for, if any.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the repository's deploys.
     *
     * @return HasMany<Build, $this>
     */
    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    /**
     * Get the pushes and pull-request events received for the repository.
     *
     * @return HasMany<RepositoryWebhookDelivery, $this>
     */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(RepositoryWebhookDelivery::class);
    }

    /**
     * Get the previews of this repository's pull requests.
     *
     * @return HasMany<Preview, $this>
     */
    public function previews(): HasMany
    {
        return $this->hasMany(Preview::class, 'source_repository_id');
    }

    /**
     * Get the preview this repository deploys, when it's a preview's own repository.
     *
     * @return HasOne<Preview, $this>
     */
    public function preview(): HasOne
    {
        return $this->hasOne(Preview::class);
    }

    /**
     * Determine whether a deploy can start: a Git provider that hosts the URL, and a live website on an active server.
     *
     * @return bool
     */
    public function isDeploymentReady(): bool
    {
        $this->loadMissing(['provider', 'website.server']);

        return $this->provider?->type->isSourceControl() === true
            && $this->provider->supportsRepositoryUrl($this->url)
            && ! $this->website->trashed()
            && $this->website->provisioning_status === Website::STATUS_ACTIVE
            && $this->website->server?->provisioning_status === Server::STATUS_ACTIVE;
    }

    /**
     * Build the provider's page URL for a full commit hash, or null.
     *
     * @param  string|null  $revision
     * @return string|null
     */
    public function revisionUrl(?string $revision): ?string
    {
        if (! is_string($revision) || preg_match('/\A[0-9a-f]{40,64}\z/D', $revision) !== 1) {
            return null;
        }
        $path = preg_replace('/\.git\z/i', '', $this->url);

        return "https://{$path}/".($this->provider?->type === \App\Enums\ProviderType::Bitbucket ? 'commits' : 'commit')."/{$revision}";
    }

    /**
     * Build the provider's page comparing two full commit hashes (what changed from the first to the second), or null.
     *
     * @param  string|null  $from
     * @param  string|null  $to
     * @return string|null
     */
    public function compareUrl(?string $from, ?string $to): ?string
    {
        foreach ([$from, $to] as $revision) {
            if (! is_string($revision) || preg_match('/\A[0-9a-f]{40,64}\z/D', $revision) !== 1) {
                return null;
            }
        }
        $path = 'https://'.preg_replace('/\.git\z/i', '', $this->url);

        return match ($this->provider?->type) {
            \App\Enums\ProviderType::GitLab => "{$path}/-/compare/{$from}...{$to}",
            \App\Enums\ProviderType::Bitbucket => "{$path}/branches/compare/{$to}%0D{$from}",
            default => "{$path}/compare/{$from}...{$to}",
        };
    }

    /**
     * Get the subdirectory to deploy, as a safe relative path (`.` for the repository root).
     *
     * @return string
     */
    public function deploymentRoot(): string
    {
        return RepositoryPath::normalizeRoot($this->deployment_root);
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts `webhook_secret` and reads the automatic-deploy path filters as JSON lists.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_deploy_include_paths' => 'array', 'auto_deploy_exclude_paths' => 'array', 'webhook_secret' => 'encrypted',
            'webhook_enabled' => 'boolean', 'webhook_pending' => 'boolean', 'webhook_last_received_at' => 'immutable_datetime',
            'previews_enabled' => 'boolean', 'preview_ttl_hours' => 'integer',
        ];
    }
}
