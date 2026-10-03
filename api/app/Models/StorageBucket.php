<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Storage\S3Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An S3-compatible bucket a project stores files in (uploads, media, exports), with the keys for it, encrypted.
 * Attaching it to an environment gives that environment the AWS_* settings Laravel's s3 disk reads.
 *
 * @property int $id
 * @property string $project_id
 * @property string|null $environment_id
 * @property string $name
 * @property string $storage_provider a key of BackupDestinationPresets
 * @property string $endpoint
 * @property string $region
 * @property string $bucket
 * @property string $access_key
 * @property string $secret_key
 * @property CarbonImmutable|null $verified_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Environment|null $environment
 */
#[Hidden(['access_key', 'secret_key'])]
class StorageBucket extends Model
{
    /**
     * Get the project the bucket belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the environment its settings were given to.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get where the bucket is and how to sign in to it.
     *
     * @return S3Location
     */
    public function location(): S3Location
    {
        return new S3Location($this->endpoint, $this->region, $this->bucket, $this->access_key, $this->secret_key);
    }

    /**
     * Get the settings Laravel's s3 disk reads, as environment variables.
     *
     * @return array<string, array{value: string, secret: bool}>
     */
    public function environmentVariables(): array
    {
        $variables = [
            'FILESYSTEM_DISK' => ['value' => 's3', 'secret' => false],
            'AWS_ACCESS_KEY_ID' => ['value' => $this->access_key, 'secret' => true],
            'AWS_SECRET_ACCESS_KEY' => ['value' => $this->secret_key, 'secret' => true],
            'AWS_DEFAULT_REGION' => ['value' => $this->region, 'secret' => false],
            'AWS_BUCKET' => ['value' => $this->bucket, 'secret' => false],
        ];
        if ($this->storage_provider !== 'amazon_s3') {
            $variables['AWS_ENDPOINT'] = ['value' => $this->endpoint, 'secret' => false];
            $variables['AWS_USE_PATH_STYLE_ENDPOINT'] = ['value' => 'true', 'secret' => false];
        }

        return $variables;
    }

    /**
     * Get the attributes that should be cast: the keys are encrypted.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['access_key' => 'encrypted', 'secret_key' => 'encrypted', 'verified_at' => 'immutable_datetime'];
    }
}
