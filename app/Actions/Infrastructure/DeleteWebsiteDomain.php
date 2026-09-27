<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\ApplyWebsiteDomains;
use App\Models\Account;
use App\Models\User;
use App\Models\WebsiteDomain;
use App\Services\Infrastructure\CloudflareDns;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DeleteWebsiteDomain
{
    public function __construct(private readonly CloudflareDns $cloudflare, private readonly RecordAuditEntry $audit) {}

    /** Remove an alias or redirect (and its Cloudflare record). The primary domain changes with the website's URL instead. */
    public function handle(Account $account, User $actor, WebsiteDomain $domain): void
    {
        Gate::forUser($actor)->authorize('update', $account);
        if ($domain->type === 'primary') {
            throw ValidationException::withMessages(['domain' => __('Change the primary domain in the website’s settings.')]);
        }
        try {
            $this->cloudflare->delete($domain);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['domain' => __('Cloudflare couldn’t remove the DNS record, so the domain was kept. Try again.')]);
        }
        $domain->delete();
        ApplyWebsiteDomains::dispatch($domain->website_id);
        $this->audit->handle(AuditAction::WebsiteDomainRemoved, $actor, $account->id, ['domain' => $domain->hostname, 'website' => $domain->website->name]);
    }
}
