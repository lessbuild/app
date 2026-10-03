<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\ApplyWebsiteDomains;
use App\Models\Account;
use App\Models\User;
use App\Models\WebsiteDomain;
use App\Services\Infrastructure\DomainDns;
use Illuminate\Support\Facades\Gate;
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
     */
    public function __construct(private readonly DomainDns $dns, private readonly RecordAuditEntry $audit) {}

    /**
     * Remove an alias or redirect (and its Cloudflare record). The primary domain changes with the website's URL instead.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  WebsiteDomain  $domain
     * @return void
     */
    public function handle(Account $account, User $actor, WebsiteDomain $domain): void
    {
        Gate::forUser($actor)->authorize('update', $domain->website);
        if ($domain->type === 'primary') {
            throw ValidationException::withMessages(['domain' => __('Change the primary domain in the website’s settings.')]);
        }
        try {
            $this->dns->delete($domain);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['domain' => __('Cloudflare couldn’t remove the DNS record, so the domain was kept. Try again.')]);
        }
        $domain->delete();
        ApplyWebsiteDomains::dispatch($domain->website_id);
        $this->audit->handle(AuditAction::WebsiteDomainRemoved, $actor, $account->id, ['domain' => $domain->hostname, 'website' => $domain->website->name]);
    }
}
