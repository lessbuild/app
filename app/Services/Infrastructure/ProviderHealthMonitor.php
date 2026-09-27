<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use App\Notifications\ProviderConnectionChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Checks a provider's credential and records the result. A provider turns "failed" after its threshold of failures in a row
 * and "healthy" after one success; its creator hears about each change. A result is dropped if the credential changed meanwhile.
 */
class ProviderHealthMonitor
{
    public function __construct(private readonly ProviderConnectionTester $tester) {}

    /** @return array{successful: bool, message: string, http_status: int|null, recorded: bool} */
    public function check(Provider $provider, bool $automatic = false): array
    {
        $token = (string) $provider->getRawOriginal('token');
        $started = hrtime(true);
        $result = $this->tester->test($provider);
        $duration = (int) min(4_294_967_295, max(0, round((hrtime(true) - $started) / 1_000_000)));

        $change = DB::transaction(function () use ($provider, $token, $result, $duration, $automatic): ?array {
            $locked = Provider::query()->lockForUpdate()->find($provider->id);
            if ($locked === null || $locked->getRawOriginal('token') !== $token || $locked->type !== $provider->type || ($automatic && ! $locked->connection_monitoring_enabled)) {
                return null;
            }
            $previous = $locked->connection_status;
            $failures = $result['successful'] ? 0 : min(65535, $locked->connection_failure_count + 1);
            $status = match (true) {
                $result['successful'] => 'healthy',
                $failures >= $locked->connection_failure_threshold => 'failed',
                default => $previous,
            };
            $checkedAt = CarbonImmutable::now('UTC');
            if ($locked->connection_checked_at !== null && $checkedAt->lessThanOrEqualTo($locked->connection_checked_at)) {
                $checkedAt = $locked->connection_checked_at->addMicrosecond();
            }
            $locked->timestamps = false;
            $locked->forceFill(['connection_status' => $status, 'connection_failure_count' => $failures, 'connection_checked_at' => $checkedAt])->save();

            $check = new ProviderConnectionCheck;
            $check->forceFill([
                'provider_id' => $locked->id, 'successful' => $result['successful'], 'source' => $automatic ? 'automatic' : 'manual',
                'provider_type' => $locked->type->value, 'http_status' => $result['http_status'], 'duration_ms' => $duration,
                'endpoint' => $this->tester->endpoint($locked->type), 'error' => $result['successful'] ? null : mb_substr($result['message'], 0, 500),
                'checked_at' => $checkedAt,
            ])->save();
            $keep = $locked->connectionChecks()->orderByDesc('checked_at')->orderByDesc('id')->limit(ProviderConnectionCheck::KEEP)->pluck('id');
            $locked->connectionChecks()->whereNotIn('id', $keep)->delete();

            return ['provider' => $locked, 'previous' => $previous, 'status' => $status];
        }, attempts: 3);

        if ($change === null) {
            return ['successful' => false, 'message' => __('The provider changed or was removed during the check. Run it again if you still need it.'), 'http_status' => null, 'recorded' => false];
        }
        $changed = $change['status'] === 'failed' ? $change['previous'] !== 'failed' : ($change['status'] === 'healthy' && $change['previous'] === 'failed');
        if ($changed) {
            $change['provider']->creator?->notify(new ProviderConnectionChanged($change['provider'], $change['status'] === 'failed', $result['message']));
        }

        return [...$result, 'recorded' => true];
    }
}
