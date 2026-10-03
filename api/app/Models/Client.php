<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An agency's client: the projects done for them, who gets their monthly report, and the markup on costs passed on.
 *
 * @property int $id
 * @property string $account_id
 * @property string $name
 * @property list<string> $emails who receives the monthly report
 * @property list<string> $project_ids
 * @property int $markup_percent added to costs passed on to the client
 * @property bool $monthly_report
 * @property string|null $last_report_month YYYY-MM of the last report sent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
final class Client extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; clients are written with forceFill.
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
        return ['emails' => 'array', 'project_ids' => 'array', 'monthly_report' => 'boolean'];
    }

    /**
     * Get the account (the agency).
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the client's projects that still exist.
     *
     * @return Collection<int, Project>
     */
    public function projects(): Collection
    {
        return Project::query()->where('account_id', $this->account_id)->whereIn('id', $this->project_ids)->orderBy('name')->get();
    }
}
