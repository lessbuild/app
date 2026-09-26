<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\Projects\Support\Hostname;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hostname a project claims. Verified once the TXT record below is published.
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $environment_id
 * @property string $hostname
 * @property string $verification_token
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_checked_at
 * @property-read Project $project
 * @property-read Environment|null $environment
 */
class Domain extends Model
{
    use HasUlids;

    public const RECORD_PREFIX = '_buildpusher';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'last_checked_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function recordName(): string
    {
        return self::RECORD_PREFIX.'.'.$this->hostname;
    }

    public function recordValue(): string
    {
        return 'buildpusher-verification='.$this->verification_token;
    }

    public function displayName(): string
    {
        return Hostname::display($this->hostname);
    }
}
