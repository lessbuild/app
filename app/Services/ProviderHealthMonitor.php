<?php

namespace App\Services;

use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use Illuminate\Support\Facades\DB;

class ProviderHealthMonitor
{
    public function __construct(
        private readonly ProviderConnectionTester $tester,
        private readonly ActivityRecorder $activity,
        private readonly IncidentNotifier $incidents,
    ) {}

    /** @return array{successful: bool, message: string, http_status: ?int, recorded: bool} */
    public function check(Provider $provider, bool $automatic = false): array
    {
        if ($automatic && ! $provider->connection_monitoring_enabled) {
            return [
                'successful' => false,
                'message' => __('Automatic connection monitoring is paused for this provider.'),
                'http_status' => null,
                'recorded' => false,
            ];
        }

        $providerType = $provider->provider;
        $encryptedToken = (string) $provider->getRawOriginal('token');
        $previousCheckedAt = $provider->getRawOriginal('connection_checked_at');
        $previousStatus = $provider->connection_status;
        $previousFailureCount = $provider->connection_failure_count;
        $failureThreshold = $provider->connection_failure_threshold;
        $endpoint = $this->tester->endpoint($providerType);
        $startedAt = hrtime(true);
        $result = $this->tester->test($provider);
        $durationMs = max(0, min((int) round((hrtime(true) - $startedAt) / 1_000_000), 4_294_967_295));

        $recorded = DB::transaction(function () use (
            $provider,
            $providerType,
            $encryptedToken,
            $previousCheckedAt,
            $previousStatus,
            $result,
            $automatic,
            $endpoint,
            $durationMs,
            $previousFailureCount,
            $failureThreshold,
        ): bool {
            if (! $provider->recordConnectionResult(
                $result['successful'],
                $providerType,
                $encryptedToken,
                $previousCheckedAt,
                $previousFailureCount,
                $failureThreshold,
                $automatic,
            )) {
                return false;
            }

            $provider->connectionChecks()->create([
                'successful' => $result['successful'],
                'source' => $automatic
                    ? ProviderConnectionCheck::SOURCE_AUTOMATIC
                    : ProviderConnectionCheck::SOURCE_MANUAL,
                'provider_type' => $providerType,
                'http_status' => $result['http_status'],
                'duration_ms' => $durationMs,
                'endpoint' => $endpoint,
                'error' => $result['successful']
                    ? null
                    : str($result['message'])->limit(500, '')->toString(),
                'checked_at' => $provider->connection_checked_at,
            ]);
            $retainedIds = $provider->connectionChecks()
                ->orderByDesc('checked_at')
                ->orderByDesc('id')
                ->limit(ProviderConnectionCheck::MAX_PER_PROVIDER)
                ->pluck('id');
            $provider->connectionChecks()->whereNotIn('id', $retainedIds)->delete();

            $this->recordTransition($provider, $previousStatus, $result);

            return true;
        });

        if (! $recorded) {
            return [
                'successful' => false,
                'message' => __('The provider changed or another check completed first. Run it again if verification is still needed.'),
                'http_status' => null,
                'recorded' => false,
            ];
        }

        return [...$result, 'recorded' => true];
    }

    /** @param array{successful: bool, message: string, http_status: ?int} $result */
    private function recordTransition(Provider $provider, ?string $previousStatus, array $result): void
    {
        $currentStatus = $provider->connection_status;
        if ($currentStatus === $previousStatus) {
            return;
        }

        if ($currentStatus === Provider::CONNECTION_FAILED) {
            $this->activity->record(
                $provider,
                $provider->user_id,
                'provider',
                "Provider \"{$provider->name}\" connection failed.",
            );
            if ($provider->user) {
                $this->incidents->fail(
                    $provider->user,
                    'provider',
                    $provider->id,
                    "Provider \"{$provider->name}\" connection failed",
                    $result['message'],
                );
            }

            return;
        }

        if ($currentStatus === Provider::CONNECTION_HEALTHY && $previousStatus === Provider::CONNECTION_FAILED) {
            $this->activity->record(
                $provider,
                $provider->user_id,
                'provider',
                "Provider \"{$provider->name}\" connection recovered.",
            );
            if ($provider->user) {
                $this->incidents->recover(
                    $provider->user,
                    'provider',
                    $provider->id,
                    "Provider \"{$provider->name}\" connection recovered",
                    __('The provider accepted its stored credential again.'),
                );
            }
        }
    }
}
