<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A move from Laravel Forge or Ploi: the other tool's API token, its servers and sites as read, and which sites have
 * been recreated here.
 *
 * @phpstan-type Site array{key: string, domain: string, root: string, web_directory: string, repository: string|null, branch: string|null, php: string|null, env: string, deploy_script: string}
 * @phpstan-type SourceServer array{id: string, name: string, ip: string|null, sites: list<Site>, crons: list<array{command: string, user: string, frequency: string}>, daemons: list<array{command: string, user: string, directory: string|null, processes: int}>}
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property string $source forge or ploi
 * @property string $token
 * @property list<SourceServer> $inventory
 * @property array<string, int>|null $moved site key => website ID
 * @property Carbon|null $fetched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
final class ToolMove extends Model
{
    /**
     * The tools a move can come from, with their names.
     *
     * @var array<string, string>
     */
    public const SOURCES = ['forge' => 'Laravel Forge', 'ploi' => 'Ploi'];

    /**
     * The attributes that can't be mass assigned: all of them; moves are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * The attributes hidden when serialised.
     *
     * @var list<string>
     */
    protected $hidden = ['token', 'inventory'];

    /**
     * Get the attribute casts; the token and everything read (environment files included) are encrypted.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['token' => 'encrypted', 'inventory' => 'encrypted:array', 'moved' => 'array', 'fetched_at' => 'datetime'];
    }

    /**
     * Get the account moving.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the tool's name.
     *
     * @return string
     */
    public function sourceName(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    /**
     * Find a site and the server it's on by its key.
     *
     * @param  string  $key
     * @return array{SourceServer, Site}|null
     */
    public function site(string $key): ?array
    {
        foreach ($this->inventory as $server) {
            foreach ($server['sites'] as $site) {
                if ($site['key'] === $key) {
                    return [$server, $site];
                }
            }
        }

        return null;
    }
}
