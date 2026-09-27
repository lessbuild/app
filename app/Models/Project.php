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
 * @property string|null $created_by_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Carbon|null $checklist_dismissed_at
 * @property Carbon|null $created_at
 * @property-read Account $account
 */
#[UseFactory(ProjectFactory::class)]
#[UsePolicy(ProjectPolicy::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['checklist_dismissed_at' => 'datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<Environment, $this> */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /** @return HasMany<EnabledService, $this> */
    public function enabledServices(): HasMany
    {
        return $this->hasMany(EnabledService::class);
    }

    public function hasService(string $service): bool
    {
        return $this->enabledServices()->where('service', $service)->exists();
    }
}
