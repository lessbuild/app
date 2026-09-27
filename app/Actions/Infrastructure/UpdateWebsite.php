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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UpdateWebsite
{
    public function __construct(private readonly WebsiteServers $servers, private readonly RecordAuditEntry $audit) {}

    /**
     * Change a website. A new server, URL or .env (or a failed website) sets it up again; moving servers keeps the old copy
     * until the new one is active, then removes it. Changing what the health check looks at resets its state.
     *
     * @param  array<string, mixed>  $data  validated by WebsiteRequest
     */
    public function handle(Account $account, User $actor, Website $website, array $data): Website
    {
        return DB::transaction(function () use ($account, $actor, $website, $data): Website {
            Gate::forUser($actor)->authorize('update', $account);
            $locked = Website::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($website->id);
            if ($locked->isProvisioning()) {
                throw ValidationException::withMessages(['server_id' => __('Wait for the current setup to finish.')]);
            }
            $server = $this->servers->handle($account, (int) $data['server_id']);
            $attributes = WebsiteAttributes::from($data);
            $moving = $server->id !== $locked->server_id;
            if ($moving && $locked->previous_server_id !== null) {
                throw ValidationException::withMessages(['server_id' => __('Finish cleaning up the previous server before moving this website again.')]);
            }
            if (! $attributes['health_check_enabled'] || ! $locked->health_check_enabled || $attributes['health_check_path'] !== $locked->health_check_path || $attributes['url'] !== $locked->url || $moving) {
                $attributes += ['health_status' => 'unknown', 'health_failure_count' => 0, 'health_last_checked_at' => null, 'health_last_error' => null];
            }
            $reprovision = $locked->provisioning_status === Website::STATUS_FAILED || $moving || $attributes['url'] !== $locked->url || $attributes['env_file'] !== (string) $locked->env_file;
            $locked->forceFill([...$attributes, 'server_id' => $server->id]);
            if ($reprovision) {
                $locked->forceFill([
                    'previous_server_id' => $moving ? $website->server_id : $locked->previous_server_id,
                    'placement_cleanup_error' => $moving ? null : $locked->placement_cleanup_error,
                    'provisioning_token' => (string) Str::uuid(), 'setup_stage' => 0, 'provisioning_status' => Website::STATUS_QUEUED,
                    'provisioning_error' => null, 'provisioned_at' => null,
                ]);
            }
            $locked->save();
            if ($reprovision) {
                $locked->logs()->where('type', 'provisioning')->delete();
                ProvisionWebsite::dispatch($locked->id, (string) $locked->provisioning_token)->afterCommit();
            }
            $this->audit->handle(AuditAction::WebsiteUpdated, $actor, $account->id, ['website' => $locked->name, 'moved' => $moving, 'reprovisioned' => $reprovision]);

            return $locked;
        }, attempts: 3);
    }
}
