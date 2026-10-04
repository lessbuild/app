<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\ProviderType;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Provider;
use App\Models\ProviderConnectionCheck;
use App\Models\Server;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/account/providers/{provider}`. */
final class ShowProviderController
{
    /**
     * Return a provider (never its token), its recent connection checks, its servers, and its settings' choices.
     *
     * @param  Account  $account
     * @param  Provider  $provider
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Provider $provider): JsonResponse
    {
        abort_unless($provider->account_id === $account->id, 404);

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'provider' => [
                ...self::summary($provider),
                'description' => $provider->description,
                'baseUrl' => $provider->base_url,
                'monitoringEnabled' => (bool) $provider->connection_monitoring_enabled,
                'checkIntervalMinutes' => $provider->connection_check_interval_minutes,
                'failureThreshold' => $provider->connection_failure_threshold,
            ],
            'checks' => $provider->connectionChecks()->orderByDesc('checked_at')->orderByDesc('id')->limit(20)->get()->map(fn (ProviderConnectionCheck $check): array => [
                'id' => $check->id,
                'checkedAt' => $check->checked_at->toIso8601String(),
                'successful' => (bool) $check->successful,
                'error' => $check->error,
                'automatic' => $check->source === 'automatic',
                'durationMs' => $check->duration_ms,
            ])->values(),
            'servers' => $provider->servers()->orderBy('name')->get()->map(fn (Server $server): string => $server->label())->values(),
            'types' => self::types(),
            'intervals' => Provider::CHECK_INTERVALS,
            'thresholds' => Provider::FAILURE_THRESHOLDS,
        ]);
    }

    /**
     * Describe a provider for lists: its name, type and connection status.
     *
     * @param  Provider  $provider
     * @return array<string, mixed>
     */
    public static function summary(Provider $provider): array
    {
        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'type' => $provider->type->value,
            'typeLabel' => $provider->type->label(),
            'purpose' => $provider->type->purpose(),
            'hostsServers' => $provider->type->hostsServers(),
            'serverCount' => (int) ($provider->servers_count ?? 0),
            'status' => $provider->connection_status ?? 'unknown',
            'checkedAt' => $provider->connection_checked_at?->toIso8601String(),
        ];
    }

    /**
     * List the types a provider can be, with what each is for.
     *
     * @return list<array{value: string, label: string, purpose: string}>
     */
    public static function types(): array
    {
        return array_map(fn (ProviderType $type): array => ['value' => $type->value, 'label' => $type->label(), 'purpose' => $type->purpose()], ProviderType::cases());
    }
}
