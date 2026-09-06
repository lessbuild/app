<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\Cache;

class MonetizationTelemetry
{
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
