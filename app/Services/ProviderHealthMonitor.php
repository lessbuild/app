<?php

namespace App\Services;

use App\Models\Provider;
use App\Notifications\FailureNotification;
use Illuminate\Support\Facades\DB;

class ProviderHealthMonitor
{
    public function __construct(
        private readonly ProviderConnectionTester $tester,
        private readonly ActivityRecorder $activity,
    ) {}

    /** @return array{successful: bool, message: string, recorded: bool} */
    public function check(Provider $provider, bool $automatic = false): array
    {
        if ($automatic && ! $provider->connection_monitoring_enabled) {
            return [
                'successful' => false,
                'message' => __('Automatic connection monitoring is paused for this provider.'),
                'recorded' => false,
            ];
        }

        $providerType = $provider->provider;
        $encryptedToken = (string) $provider->getRawOriginal('token');
        $previousCheckedAt = $provider->getRawOriginal('connection_checked_at');
        $previousStatus = $provider->connection_status;
        $result = $this->tester->test($provider);

        $recorded = DB::transaction(function () use (
            $provider,
            $providerType,
            $encryptedToken,
            $previousCheckedAt,
            $previousStatus,
            $result,
            $automatic,
        ): bool {
            if (! $provider->recordConnectionResult(
                $result['successful'],
                $providerType,
                $encryptedToken,
                $previousCheckedAt,
                $automatic,
            )) {
                return false;
            }

            $this->recordTransition($provider, $previousStatus, $result);

            return true;
        });

        if (! $recorded) {
            return [
                'successful' => false,
                'message' => __('The provider changed or another check completed first. Run it again if verification is still needed.'),
                'recorded' => false,
            ];
        }

        return [...$result, 'recorded' => true];
    }

    /** @param array{successful: bool, message: string} $result */
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
            $provider->user?->notify(new FailureNotification(
                'provider',
                $provider->id,
                "Provider \"{$provider->name}\" connection failed",
                $result['message'],
            ));

            return;
        }

        if ($currentStatus === Provider::CONNECTION_HEALTHY && $previousStatus === Provider::CONNECTION_FAILED) {
            $this->activity->record(
                $provider,
                $provider->user_id,
                'provider',
                "Provider \"{$provider->name}\" connection recovered.",
            );
            $provider->user?->notify(new FailureNotification(
                'provider',
                $provider->id,
                "Provider \"{$provider->name}\" connection recovered",
                __('The provider accepted its stored credential again.'),
                'healthy',
            ));
        }
    }
}
