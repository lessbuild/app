<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AlertDelivery;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use App\Notifications\IncidentAlertNotification;
use Illuminate\Database\Eloquent\Model;
use stdClass;

trait MonitoringHelpers
{
    /** The account's owner, created on first use (factories make accounts without members). */
    protected function ownerOf(Account|Project|Environment|Monitor $subject): User
    {
        $account = match (true) {
            $subject instanceof Monitor => $subject->environment->project->account,
            $subject instanceof Environment => $subject->project->account,
            $subject instanceof Project => $subject->account,
            default => $subject,
        };
        $owner = Membership::query()->where('account_id', $account->id)->where('role', AccountRole::Owner->value)->first()?->user;
        if ($owner !== null) {
            return $owner;
        }
        $owner = User::factory()->create();
        Account::query()->findOrFail($account->id);
        $membership = new Membership;
        $membership->forceFill(['account_id' => $account->id, 'user_id' => $owner->id, 'role' => AccountRole::Owner])->save();
        $owner->forceFill(['current_account_id' => $account->id])->save();

        return $owner;
    }

    /** The alert email for a delivery, rendered as HTML. */
    protected function alertEmail(AlertDelivery $delivery): string
    {
        return (string) (new IncidentAlertNotification($delivery->id, $delivery->payload))->toMail(new stdClass)->render();
    }

    /**
     * The model as stored now; fails the test when it's gone.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    protected function reload(Model $model): Model
    {
        $fresh = $model->fresh();
        $this->assertNotNull($fresh);

        return $fresh;
    }

    /**
     * Add someone to the account with a role (and, optionally, only some services).
     *
     * @param  list<string>|null  $services
     */
    protected function addMember(Account|Project|Environment $subject, User $user, AccountRole $role, ?array $services = null): Membership
    {
        $accountId = match (true) {
            $subject instanceof Environment => $subject->project->account_id,
            $subject instanceof Project => $subject->account_id,
            default => $subject->id,
        };
        $membership = new Membership;
        $membership->forceFill(['account_id' => $accountId, 'user_id' => $user->id, 'role' => $role, 'service_access' => $services])->save();

        return $membership;
    }
}
