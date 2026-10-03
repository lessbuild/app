<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A password manager an environment's secrets come from: Doppler, a 1Password Connect server or AWS Secrets Manager,
 * with the customer's own credentials (encrypted). Its values become secret variables it manages.
 *
 * @property int $id
 * @property string $environment_id
 * @property string|null $created_by
 * @property string $provider doppler, onepassword or aws
 * @property string $name
 * @property array<string, string> $settings doppler: token; onepassword: host, token, vault, item; aws: region, access_key, secret_key, secret_id
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 * @property array{added: int, updated: int, removed: int, skipped: list<string>}|null $last_result
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Environment $environment
 */
final class SecretSync extends Model
{
    /**
     * The providers, with their names.
     *
     * @var array<string, string>
     */
    public const PROVIDERS = ['doppler' => 'Doppler', 'onepassword' => '1Password Connect', 'aws' => 'AWS Secrets Manager'];

    /**
     * The attributes that can't be mass assigned: all of them; syncs are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * The attributes never serialised: the credentials.
     *
     * @var list<string>
     */
    protected $hidden = ['settings'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['settings' => 'encrypted:array', 'last_synced_at' => 'datetime', 'last_result' => 'array'];
    }

    /**
     * Get the environment the secrets go to.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
