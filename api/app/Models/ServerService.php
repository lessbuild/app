<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsServerTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A service installed on a server with one click: a search engine (Meilisearch or Typesense) or a Redis cache, with
 * the key or password it was given.
 *
 * @property int $id
 * @property int $server_id
 * @property string $kind meilisearch, typesense or redis
 * @property int $port
 * @property string $secret the master key, API key or password (encrypted)
 * @property string $listen local (this server only) or private (also the private network)
 * @property string $status pending, active, removing or failed
 * @property string|null $error
 * @property CarbonImmutable|null $applied_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
#[Hidden(['secret'])]
class ServerService extends Model
{
    use IsServerTask {
        casts as private taskCasts;
    }

    /**
     * The services that can be installed: their name, port, what the secret is called, and what they're for.
     *
     * @var array<string, array{name: string, port: int, secret: string, description: string}>
     */
    public const KINDS = [
        'meilisearch' => ['name' => 'Meilisearch', 'port' => 7700, 'secret' => 'Master key', 'description' => 'Fast, typo-tolerant search for Laravel Scout.'],
        'typesense' => ['name' => 'Typesense', 'port' => 8108, 'secret' => 'API key', 'description' => 'Search engine with a built-in admin API, also for Laravel Scout.'],
        'redis' => ['name' => 'Redis', 'port' => 6379, 'secret' => 'Password', 'description' => 'Cache, sessions and queues.'],
    ];

    /**
     * Get the service's name.
     *
     * @return string
     */
    public function name(): string
    {
        return self::KINDS[$this->kind]['name'] ?? $this->kind;
    }

    /**
     * Get the address apps on this server use to reach it.
     *
     * @return string
     */
    public function localAddress(): string
    {
        return ($this->kind === 'redis' ? 'redis://' : 'http://').'127.0.0.1:'.$this->port;
    }

    /**
     * Get the attributes that should be cast: the secret is encrypted.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [...$this->taskCasts(), 'secret' => 'encrypted'];
    }
}
