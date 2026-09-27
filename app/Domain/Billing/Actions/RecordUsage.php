<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\UsageRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class RecordUsage
{
    /** Add usage of a meter (e.g. `monitoring.events`) to the account's hourly bucket. Cheap enough to call per batch. */
    public function handle(string $accountId, string $meter, int $quantity, ?CarbonInterface $at = null): void
    {
        if ($quantity <= 0) {
            return;
        }
        $hour = ($at ?? now())->copy()->utc()->startOfHour();

        DB::transaction(function () use ($accountId, $meter, $quantity, $hour): void {
            $updated = UsageRecord::query()->where('account_id', $accountId)->where('meter', $meter)->where('period_start', $hour)->increment('quantity', $quantity);
            if ($updated === 0) {
                (new UsageRecord)->forceFill(['account_id' => $accountId, 'meter' => $meter, 'period_start' => $hour, 'quantity' => $quantity])->save();
            }
        });
    }
}
