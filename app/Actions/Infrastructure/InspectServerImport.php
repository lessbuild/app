<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\Server;
use App\Models\ServerImportAssessment;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Infrastructure\ServerDiscovery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class InspectServerImport
{
    public function __construct(private readonly Entitlements $entitlements, private readonly ServerDiscovery $discovery) {}

    /**
     * Look at an existing server over SSH, without changing it, and keep the findings for 30 minutes. Returns the
     * assessment and the one-time token that confirms it.
     *
     * @param  array{name: string, type: string, public_ip: string, ssh_port: int|string, ssh_private_key: string}  $data
     * @return array{ServerImportAssessment, string}
     */
    public function handle(Account $account, User $actor, array $data): array
    {
        Gate::forUser($actor)->authorize('update', $account);
        if (! $this->entitlements->for($account)->allows('infrastructure.servers.max', Server::query()->where('account_id', $account->id)->count() + 1)->allowed) {
            throw ValidationException::withMessages(['plan' => __('Your plan’s server limit has been reached.')]);
        }
        $configuration = ['name' => $data['name'], 'type' => $data['type'], 'public_ip' => $data['public_ip'], 'ssh_port' => (int) $data['ssh_port'], 'ssh_private_key' => trim($data['ssh_private_key'])];
        try {
            $report = $this->discovery->inspect($configuration);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['connection' => Str::limit($exception->getMessage(), 1000)]);
        }
        $token = Str::random(64);
        $assessment = new ServerImportAssessment;
        $assessment->forceFill([
            'account_id' => $account->id, 'user_id' => $actor->id, 'token_hash' => hash('sha256', $token),
            'configuration' => $configuration, 'report' => $report, 'expires_at' => CarbonImmutable::now('UTC')->addMinutes(30),
        ])->save();

        return [$assessment, $token];
    }
}
