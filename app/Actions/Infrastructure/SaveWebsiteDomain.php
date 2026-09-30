<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Enums\ProviderType;
use App\Jobs\Infrastructure\ApplyWebsiteDomains;
use App\Models\Account;
use App\Models\Provider;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteDomain;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveWebsiteDomain
{
    /**
     * Create a new SaveWebsiteDomain instance.
     *
     * Adds or changes a website's domain.
     *
     * @param  SyncWebsiteDomain  $sync  Creates its DNS record at Cloudflare when a DNS provider is chosen.
     * @param  RecordAuditEntry  $audit  Records the change.
     */
    public function __construct(private readonly SyncWebsiteDomain $sync, private readonly RecordAuditEntry $audit) {}

    /**
     * Add an alias or redirect to a website and apply it to Caddy. With a Cloudflare provider its DNS record is created too.
     * Returns the domain and, if something needs the user's attention, a warning.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  Website  $website
     * @param  array{hostname: string, type: string, redirect_url?: string|null, dns_provider_id?: int|string|null, is_temporary?: bool}  $data
     * @return array{WebsiteDomain, string|null}
     */
    public function handle(Account $account, User $actor, Website $website, array $data): array
    {
        Gate::forUser($actor)->authorize('update', $website);
        $website = Website::query()->where('account_id', $account->id)->findOrFail($website->id);
        $providerId = isset($data['dns_provider_id']) && $data['dns_provider_id'] !== '' ? (int) $data['dns_provider_id'] : null;
        if ($providerId !== null && ! Provider::query()->where('account_id', $account->id)->whereKey($providerId)->whereIn('type', array_filter(ProviderType::cases(), fn (ProviderType $type): bool => $type->managesDns()))->exists()) {
            throw ValidationException::withMessages(['dns_provider_id' => __('Choose one of this account’s DNS providers.')]);
        }
        $domain = new WebsiteDomain;
        $domain->forceFill([
            'website_id' => $website->id, 'created_by' => $actor->id, 'dns_provider_id' => $providerId, 'hostname' => $data['hostname'], 'type' => $data['type'],
            'redirect_url' => $data['type'] === 'redirect' ? ($data['redirect_url'] ?? null) : null, 'is_temporary' => (bool) ($data['is_temporary'] ?? false),
        ])->save();
        $warning = $providerId === null ? (string) __('Domain added. Point its DNS at the server, then check its certificate.') : $this->sync->handle($domain);
        ApplyWebsiteDomains::dispatch($website->id);
        $this->audit->handle(AuditAction::WebsiteDomainAdded, $actor, $account->id, ['domain' => $domain->hostname, 'website' => $website->name, 'type' => $domain->type]);

        return [$domain, $warning];
    }
}
