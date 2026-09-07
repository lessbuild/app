<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\Cache;

class MonetizationTelemetry
{
    /**
     * Increment daily UTC plan-denial counters without storing request payloads.
     *
     * @param  string  $type  The denial category, such as entitlement or limit.
     * @param  string  $capability  The feature or resource key that was denied.
     * @param  Organization|null  $organization  The optional workspace whose owner's plan is counted.
     * @return void No value; increments total, category, capability and optional plan counters.
     */
    public function denied(string $type, string $capability, ?Organization $organization = null): void
    {
        $date = now()->utc()->toDateString();
        Cache::increment("business:denials:{$date}:total");
        Cache::increment("business:denials:{$date}:{$type}");
        Cache::increment("business:denials:{$date}:capability:{$capability}");
        if ($organization) {
            Cache::increment("business:denials:{$date}:plan:{$organization->owner->billingPlan()}");
        }
    }

    /** @return array{total: int, limits: int, entitlements: int} */
    public function deniedForDate(string $date): array
    {
        return [
            'total' => (int) Cache::get("business:denials:{$date}:total", 0),
            'limits' => (int) Cache::get("business:denials:{$date}:limit", 0),
            'entitlements' => (int) Cache::get("business:denials:{$date}:entitlement", 0),
        ];
    }
}
