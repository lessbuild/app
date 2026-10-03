<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\OnCallOverride;
use App\Models\OnCallSchedule;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteOnCallRecord
{
    /**
     * Delete a schedule (destinations that followed it stop sending until they're given a recipient) or an override.
     *
     * @param  User  $actor
     * @param  OnCallSchedule|OnCallOverride  $record
     * @return void
     */
    public function handle(User $actor, OnCallSchedule|OnCallOverride $record): void
    {
        Gate::forUser($actor)->authorize($record instanceof OnCallSchedule ? 'delete' : 'update', $record instanceof OnCallSchedule ? $record : $record->schedule);
        $record->delete();
    }
}
