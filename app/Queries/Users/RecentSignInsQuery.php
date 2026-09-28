<?php

declare(strict_types=1);

namespace App\Queries\Users;

use App\Data\Users\SignInSummary;
use App\Models\SignInEvent;
use App\Models\User;
use App\Support\DeviceLabel;
use Carbon\CarbonImmutable;

final class RecentSignInsQuery
{
    /**
     * The person's latest sign-in attempts, newest first, with a readable device.
     *
     * @param  User  $user
     * @param  int  $limit
     * @return list<SignInSummary>
     */
    public function handle(User $user, int $limit = 25): array
    {
        return array_values(SignInEvent::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (SignInEvent $event): SignInSummary => new SignInSummary(
                succeeded: $event->succeeded,
                method: $event->method,
                twoFactor: $event->two_factor,
                device: DeviceLabel::from($event->user_agent),
                ipAddress: $event->ip_address,
                at: CarbonImmutable::instance($event->created_at),
            ))
            ->all());
    }
}
