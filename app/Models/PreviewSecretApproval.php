<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone's approval for a preview's revision to receive chosen runtime secrets from its source environment. It
 * stores which variables at which versions, never their values, and lapses when the revision or a version changes.
 *
 * @property int $id
 * @property int $preview_id
 * @property string $revision
 * @property string $source_environment_id
 * @property string|null $approved_by
 * @property array<string, int> $variable_versions variable ID => the version approved
 * @property CarbonImmutable $approved_at
 * @property CarbonImmutable|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Preview $preview
 * @property-read User|null $approver
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class PreviewSecretApproval extends Model
{
    /**
     * Keys a preview always sets itself, so an approval can never replace them with the source environment's values.
     *
     * @var list<string>
     */
    public const PROTECTED_KEYS = ['APP_ENV', 'APP_DEBUG', 'APP_KEY', 'APP_URL', 'BUILDPUSHER_PREVIEW', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    /**
     * Get the preview the approval is for.
     *
     * @return BelongsTo<Preview, $this>
     */
    public function preview(): BelongsTo
    {
        return $this->belongsTo(Preview::class);
    }

    /**
     * Get who approved the secrets.
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the variable versions as JSON and the timestamps as immutable dates.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['variable_versions' => 'array', 'approved_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
