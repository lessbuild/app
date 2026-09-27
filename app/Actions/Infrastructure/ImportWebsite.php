<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\User;
use App\Models\Website;
use App\Services\Billing\Entitlements;
use App\Services\Infrastructure\ServerShell;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ImportWebsite
{
    public function __construct(private readonly Entitlements $entitlements, private readonly WebsiteServers $servers, private readonly ServerShell $shell, private readonly RecordAuditEntry $audit) {}

    /**
     * Adopt an application already in /var/www/{slug} on an app server, without touching its files, proxy or database.
     *
     * @param  array{server_id: int|string, name: string, url: string, deployment_slug: string, description?: string|null}  $data
     */
    public function handle(Account $account, User $actor, array $data): Website
    {
        Gate::forUser($actor)->authorize('update', $account);
        if (! $this->entitlements->for($account)->allows('deploy.websites.max', Website::query()->where('account_id', $account->id)->count() + 1)->allowed) {
            throw ValidationException::withMessages(['plan' => __('Your plan’s website limit has been reached.')]);
        }
        $server = $this->servers->handle($account, (int) $data['server_id']);
        if (Website::withTrashed()->where('account_id', $account->id)->where('deployment_slug', $data['deployment_slug'])->exists()) {
            throw ValidationException::withMessages(['deployment_slug' => __('Another website already uses that directory.')]);
        }
        $root = escapeshellarg('/var/www/'.$data['deployment_slug']);
        if (! $this->shell->run($server, "test -d {$root} && test -r {$root}")->successful()) {
            throw ValidationException::withMessages(['deployment_slug' => __('There’s no readable application directory with that name under /var/www on this server.')]);
        }
        $description = trim((string) ($data['description'] ?? ''));
        $website = new Website;
        $website->forceFill([
            'account_id' => $account->id, 'created_by' => $actor->id, 'server_id' => $server->id, 'name' => trim($data['name']),
            'description' => $description !== '' ? $description : null, 'url' => $data['url'], 'deployment_slug' => $data['deployment_slug'],
            'env_file' => '', 'database_password' => Str::password(32, symbols: false), 'provisioning_status' => Website::STATUS_ACTIVE,
            'provisioned_at' => CarbonImmutable::now('UTC'), 'health_check_enabled' => false,
        ])->save();
        $this->audit->handle(AuditAction::WebsiteImported, $actor, $account->id, ['website' => $website->name, 'server' => $server->label()]);

        return $website;
    }
}
