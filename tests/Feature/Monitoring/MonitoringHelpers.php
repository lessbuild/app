<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Enums\AccountRole;
use App\Enums\SelectionKind;
use App\Models\Account;
use App\Models\AlertDelivery;
use App\Models\AlertRule;
use App\Models\BillingSelection;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use App\Notifications\IncidentAlertNotification;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

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
     * How many elements of a page match an XPath query.
     *
     * @param  TestResponse<Response>  $response
     */
    protected function xpathCount(TestResponse $response, string $query): int
    {
        $document = new DOMDocument;
        $document->loadHTML((string) $response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $nodes = (new DOMXPath($document))->query($query);
        $this->assertNotFalse($nodes);

        return $nodes->length;
    }

    /**
     * A value from an alert rule's last observation.
     */
    protected function observed(AlertRule $rule, string $key): mixed
    {
        return $this->reload($rule)->observation[$key] ?? null;
    }

    /**
     * Runs an Artisan command with output expectations.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /**
     * The model as stored now; fails the test when it's gone.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     *
     * @phpstan-impure
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

    /**
     * Make every insert into a table fail, to test that a write failure rolls everything back (SQLite and Postgres).
     *
     * @param  literal-string  $table
     * @param  literal-string  $trigger
     * @param  literal-string  $message
     */
    protected function rejectInserts(string $table, string $trigger, string $message): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION {$trigger}() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION '{$message}'; END; \$\$ LANGUAGE plpgsql");
            DB::unprepared("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION {$trigger}()");

            return;
        }
        DB::unprepared("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
    }

    /**
     * @param  literal-string  $table
     * @param  literal-string  $trigger
     */
    protected function allowInserts(string $table, string $trigger): void
    {
        DB::unprepared(DB::getDriverName() === 'pgsql' ? "DROP TRIGGER {$trigger} ON {$table}" : "DROP TRIGGER {$trigger}");
    }

    /** Put an account on a paid Monitoring tier (pro, team or scale). */
    protected function onMonitoringTier(Account|Project|Environment $subject, string $tier): void
    {
        $this->onTier($subject, 'monitoring', $tier);
    }

    /** Put the account on a service's tier. */
    protected function onTier(Account|Project|Environment $subject, string $service, string $tier): void
    {
        $accountId = match (true) {
            $subject instanceof Environment => $subject->project->account_id,
            $subject instanceof Project => $subject->account_id,
            default => $subject->id,
        };
        $selection = BillingSelection::query()->where('account_id', $accountId)->where('service', $service)
            ->where('kind', SelectionKind::Tier)->first() ?? new BillingSelection;
        $selection->forceFill(['account_id' => $accountId, 'service' => $service, 'kind' => SelectionKind::Tier, 'item_key' => $tier, 'quantity' => 1])->save();
    }

    /**
     * Submit the "add an alert rule" form for the payload's environment.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    protected function postRule(array $payload): TestResponse
    {
        return $this->post(route('monitoring.rules.store', Environment::query()->whereKey($payload['environment_id'])->value('project_id')), $payload);
    }

    /**
     * Submit the "add an objective" form for the payload's environment.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    protected function postObjective(array $payload): TestResponse
    {
        return $this->post(route('monitoring.objectives.store', Environment::query()->whereKey($payload['environment_id'])->value('project_id')), $payload);
    }

    /** The retry URL for an alert delivery, under a project of the delivery's account (created if needed). */
    protected function retryUrl(AlertDelivery $delivery): string
    {
        $project = Project::query()->where('account_id', $delivery->account_id)->first()
            ?? Project::factory()->for($delivery->account)->withServices(['monitoring'])->create();

        return route('monitoring.deliveries.retry', [$project, $delivery->id]);
    }
}
