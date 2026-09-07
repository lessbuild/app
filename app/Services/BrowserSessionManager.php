<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

class BrowserSessionManager
{
    public const MAX_VISIBLE_SESSIONS = 20;

    /**
     * Bind client metadata normalization for browser-session displays.
     *
     * @param  ClientMetadata  $clients  Formats the browser, device and address associated with each session.
     */
    public function __construct(private readonly ClientMetadata $clients) {}

    /**
     * Check whether the configured session driver supports stored-session management.
     *
     * @return bool True only for database-backed sessions.
     */
    public function available(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return Collection<int, array{
     *     id: string,
     *     device: string,
     *     ip_address: string,
     *     last_active_at: CarbonImmutable,
     *     is_current: bool
     * }>
     */
    public function activeFor(User $user, string $currentSessionId): Collection
    {
        if (! $this->available()) {
            return collect();
        }

        $activeSince = now()->subMinutes((int) config('session.lifetime', 120))->timestamp;

        return $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', $activeSince)
            ->select(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->orderByDesc('last_activity')
            ->limit(self::MAX_VISIBLE_SESSIONS)
            ->get()
            ->map(fn (object $session): array => [
                'id' => (string) $session->id,
                'device' => $this->clients->deviceName($session->user_agent),
                'ip_address' => $this->clients->displayIp($session->ip_address),
                'last_active_at' => CarbonImmutable::createFromTimestampUTC((int) $session->last_activity),
                'is_current' => hash_equals($currentSessionId, (string) $session->id),
            ]);
    }

    /**
     * Delete one session owned by the user while protecting the current session.
     *
     * @param  User  $user  The account that must own the stored session.
     * @param  string  $sessionId  The stored session identifier to revoke.
     * @param  string  $currentSessionId  The active session identifier that must be retained.
     * @return 'unavailable'|'current'|'revoked'|'missing' The outcome of the ownership-scoped deletion.
     */
    public function revoke(User $user, string $sessionId, string $currentSessionId): string
    {
        if (! $this->available()) {
            return 'unavailable';
        }

        if (hash_equals($currentSessionId, $sessionId)) {
            return 'current';
        }

        $deleted = $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getKey())
            ->where('id', $sessionId)
            ->delete();

        return $deleted === 1 ? 'revoked' : 'missing';
    }

    /**
     * Delete the user's database sessions except the current browser session.
     *
     * @param  User  $user  The account whose other sessions should be revoked.
     * @param  string  $currentSessionId  The session identifier to retain.
     * @return int The number deleted, or zero when database sessions are unavailable.
     */
    public function revokeOthers(User $user, string $currentSessionId): int
    {
        if (! $this->available()) {
            return 0;
        }

        return $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    /**
     * Resolve the configured database connection for session records.
     *
     * @return ConnectionInterface The session database connection, falling back to the default connection.
     */
    private function connection(): ConnectionInterface
    {
        return app('db')->connection(config('session.connection'));
    }

    /**
     * Resolve the configured session-table name.
     *
     * @return string The configured name, defaulting to sessions.
     */
    private function table(): string
    {
        return (string) config('session.table', 'sessions');
    }
}
