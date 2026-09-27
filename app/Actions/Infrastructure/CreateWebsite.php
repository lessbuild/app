<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Data\Infrastructure\WebsiteAttributes;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\ProvisionWebsite;
use App\Models\Account;
use App\Models\User;
use App\Models\Website;
use App\Services\Billing\Entitlements;
use App\Services\Infrastructure\WebsiteHealthChecks;
use App\Support\Infrastructure\WebsiteEnvironment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateWebsite
{
    /**
     * Creates a website on a server and starts provisioning it.
     *
     * @param  Entitlements  $entitlements  Checks the plan's website limit.
     * @param  WebsiteServers  $servers  Checks the server can host websites.
     * @param  WebsiteHealthChecks  $health  Creates its health monitor when monitoring is on.
     * @param  RecordAuditEntry  $audit  Records the new website.
     */
    public function __construct(private readonly Entitlements $entitlements, private readonly WebsiteServers $servers, private readonly WebsiteHealthChecks $health, private readonly RecordAuditEntry $audit) {}

    /**
     * Create a website on an app server and set it up over SSH. Counts against `deploy.websites.max`.
     *
     * @param  array<string, mixed>  $data  validated by WebsiteRequest
     */
    public function handle(Account $account, User $actor, array $data): Website
    {
        return DB::transaction(function () use ($account, $actor, $data): Website {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($actor)->authorize('create', [Website::class, $account]);
            $decision = $this->entitlements->for($account)->allows('deploy.websites.max', Website::query()->where('account_id', $account->id)->count() + 1);
            if (! $decision->allowed) {
                throw ValidationException::withMessages(['plan' => $decision->reason]);
            }
            $server = $this->servers->handle($account, (int) $data['server_id']);
            $website = new Website;
            $website->forceFill([
                ...WebsiteAttributes::from($data),
                'account_id' => $account->id, 'created_by' => $actor->id, 'server_id' => $server->id, 'environment_id' => WebsiteEnvironment::resolve($account, $data['environment_id'] ?? null),
                'database_password' => Str::random(32), 'provisioning_status' => Website::STATUS_QUEUED,
            ])->save();
            ProvisionWebsite::dispatch($website->id, (string) $website->provisioning_token)->afterCommit();
            $this->health->sync($website, $actor);
            $this->audit->handle(AuditAction::WebsiteCreated, $actor, $account->id, ['website' => $website->name, 'server' => $server->label(), 'url' => $website->url]);

            return $website;
        }, attempts: 3);
    }
}
