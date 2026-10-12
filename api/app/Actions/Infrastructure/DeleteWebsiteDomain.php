<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\ApplyWebsiteDomains;
use App\Jobs\Infrastructure\ProvisionWebsite;
use App\Models\Account;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteDomain;
use App\Services\Infrastructure\DomainDns;
use App\Services\Infrastructure\WebsiteHealthChecks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DeleteWebsiteDomain
{
    /**
     * Create a new DeleteWebsiteDomain instance.
     *
     * Removes a domain from a website.
     *
     * @param  DomainDns  $dns  Deletes its DNS record at the provider that manages it.
     * @param  RecordAuditEntry  $audit  Records the removal.
     * @param  WebsiteHealthChecks  $health  Points the website's health check at its new main domain.
     */
    public function __construct(private readonly DomainDns $dns, private readonly RecordAuditEntry $audit, private readonly WebsiteHealthChecks $health) {}

    /**
     * Remove a domain from a website, and its DNS record where we manage it. Removing the primary domain makes another
     * of the website's domains primary (a real alias before a temporary one; a redirect can't serve the site) and sets
     * the website up again under it, as changing its address does; a website with no other domain keeps it.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  WebsiteDomain  $domain
     * @return string|null The domain that became primary, if the primary one was removed.
     */
    public function handle(Account $account, User $actor, WebsiteDomain $domain): ?string
    {
        Gate::forUser($actor)->authorize('update', $domain->website);
        $website = $domain->website;
        $next = null;
        if ($domain->type === 'primary') {
            if ($website->isProvisioning()) {
                throw ValidationException::withMessages(['domain' => __('Wait for the website’s setup to finish, then remove the domain.')]);
            }
            $next = $website->domains()->whereKeyNot($domain->id)->where('type', 'alias')->orderBy('is_temporary')->orderBy('id')->first();
            if ($next === null) {
                throw ValidationException::withMessages(['domain' => __('A website needs a domain. Add another domain (or get a temporary one) first, then remove :domain.', ['domain' => $domain->hostname])]);
            }
        }
        try {
            $this->dns->delete($domain);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['domain' => __('Cloudflare couldn’t remove the DNS record, so the domain was kept. Try again.')]);
        }
        DB::transaction(function () use ($domain, $next, $website): void {
            $domain->delete();
            if ($next === null) {
                return;
            }
            $next->forceFill(['type' => 'primary'])->save();
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            $locked->forceFill([
                'url' => $next->hostname,
                'health_status' => 'unknown', 'health_failure_count' => 0, 'health_last_checked_at' => null, 'health_last_error' => null,
                'provisioning_token' => (string) Str::uuid(), 'setup_stage' => 0, 'provisioning_status' => Website::STATUS_QUEUED,
                'provisioning_error' => null, 'provisioned_at' => null,
            ])->save();
            $locked->logs()->where('type', 'provisioning')->delete();
            ProvisionWebsite::dispatch($locked->id, (string) $locked->provisioning_token)->afterCommit();
        });
        if ($next === null) {
            ApplyWebsiteDomains::dispatch($domain->website_id);
        } else {
            $this->health->sync($website->refresh(), $actor);
        }
        $this->audit->handle(AuditAction::WebsiteDomainRemoved, $actor, $account->id, ['domain' => $domain->hostname, 'website' => $website->name]);

        return $next?->hostname;
    }
}
