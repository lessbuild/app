<?php

declare(strict_types=1);

namespace App\Domain\Identity\Queries;

use App\Domain\Identity\Data\BrowserSession;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\BrowserSessions;
use App\Domain\Identity\Support\DeviceLabel;
use Carbon\CarbonImmutable;

final class BrowserSessionsQuery
{
    public function __construct(private readonly BrowserSessions $sessions) {}

    /** @return list<BrowserSession>|null null when the session store can't list sessions */
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
