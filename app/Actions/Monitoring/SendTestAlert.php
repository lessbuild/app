<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Exceptions\StateConflict;
use App\Models\Account;
use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\User;
use App\Services\Monitoring\AlertDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SendTestAlert
{
    public function __construct(private readonly AlertDispatcher $alerts) {}

    public function handle(Account $account, User $actor, AlertDestination $destination, int $version): AlertDelivery
    {
        return DB::transaction(function () use ($account, $actor, $destination, $version): AlertDelivery {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize('update', $destination);
            $destination = AlertDestination::forAccount($account)->lockForUpdate()->findOrFail($destination->id);
            StateConflict::unlessVersion($destination->state_version, $version, __('This destination changed. Refresh before trying again.'));
            StateConflict::unless($destination->enabled, __('Turn this destination on before sending a test.'));

            return $this->alerts->queue($destination, [
                'event' => 'test', 'title' => 'Test notification', 'incident_id' => null,
                'application' => config('app.name').' test', 'project' => config('app.name').' test',
                'environment' => 'Test only', 'url' => null,
                'rule' => null, 'observation' => null, 'opened_at' => null, 'resolved_at' => null,
            ]);
        }, attempts: 3);
    }
}
