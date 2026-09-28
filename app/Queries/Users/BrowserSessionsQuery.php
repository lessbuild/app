<?php

declare(strict_types=1);

namespace App\Queries\Users;

use App\Data\Users\BrowserSession;
use App\Models\User;
use App\Services\BrowserSessions;
use App\Support\DeviceLabel;
use Carbon\CarbonImmutable;

final class BrowserSessionsQuery
{
    /**
     * Lists someone's signed-in browsers.
     *
     * @param  BrowserSessions  $sessions  Reads sessions from the session store.
     */
    public function __construct(private readonly BrowserSessions $sessions) {}

    /**
     * The person's 50 most recent sessions with a readable device and whether each is the current one; null when the
     * session driver can't list sessions.
     *
     * @param  User  $user
     * @param  string  $currentSessionId
     * @return list<BrowserSession>|null null when the session store can't list sessions
     */
    public function handle(User $user, string $currentSessionId): ?array
    {
        if (! $this->sessions->available()) {
            return null;
        }

        $rows = $this->sessions->for($user)->orderByDesc('last_activity')->limit(50)->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return array_values($rows->map(fn (object $row): BrowserSession => new BrowserSession(
            id: (string) $row->id,
            device: DeviceLabel::from(is_string($row->user_agent) ? $row->user_agent : null),
            ipAddress: is_string($row->ip_address) ? $row->ip_address : null,
            lastActiveAt: CarbonImmutable::createFromTimestamp((int) $row->last_activity),
            current: $row->id === $currentSessionId,
        ))->all());
    }
}
