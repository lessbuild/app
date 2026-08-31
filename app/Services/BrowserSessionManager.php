<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BrowserSessionManager
{
    public const MAX_VISIBLE_SESSIONS = 20;

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
                'device' => $this->deviceName($session->user_agent),
                'ip_address' => filled($session->ip_address)
                    ? Str::limit((string) $session->ip_address, 45, '')
                    : __('Unknown IP address'),
                'last_active_at' => CarbonImmutable::createFromTimestampUTC((int) $session->last_activity),
                'is_current' => hash_equals($currentSessionId, (string) $session->id),
            ]);
    }

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

    private function connection(): ConnectionInterface
    {
        return app('db')->connection(config('session.connection'));
    }

    private function table(): string
    {
        return (string) config('session.table', 'sessions');
    }

    private function deviceName(?string $userAgent): string
    {
        if (blank($userAgent)) {
            return __('Unknown browser or device');
        }

        $agent = strtolower($userAgent);
        $browser = match (true) {
            str_contains($agent, 'edg/') => 'Edge',
            str_contains($agent, 'firefox/'), str_contains($agent, 'fxios/') => 'Firefox',
            str_contains($agent, 'chrome/'), str_contains($agent, 'crios/') => 'Chrome',
            str_contains($agent, 'safari/') => 'Safari',
            default => __('Unknown browser'),
        };
        $platform = match (true) {
            str_contains($agent, 'iphone') => 'iPhone',
            str_contains($agent, 'ipad') => 'iPad',
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'macintosh'), str_contains($agent, 'mac os x') => 'macOS',
            str_contains($agent, 'linux') => 'Linux',
            default => __('Unknown device'),
        };

        return __(':browser on :platform', compact('browser', 'platform'));
    }
}
