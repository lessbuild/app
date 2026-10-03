<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A template saved from one of the account's projects: its services, environments, how production builds and runs,
 * its uptime checks and its Analytics goals. Variable names are kept, never their values.
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property string $name
 * @property string $description
 * @property array<string, mixed> $definition in the shape of config/templates.php, plus environments, monitors, goals and settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
final class ProjectTemplate extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; templates are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    /**
     * Get the account it belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the key it's chosen by on the templates page.
     *
     * @return string
     */
    public function key(): string
    {
        return 'saved-'.$this->id;
    }
}
