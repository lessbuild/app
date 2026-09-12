<?php

namespace App\Actions\Domain;

use App\Data\WebsiteDomainSaveResult;
use App\Exceptions\WebsiteDomainOperationException;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Str;

class IssueTemporaryWebsiteDomainAction
{
    public function __construct(private readonly SaveWebsiteDomainAction $saveDomain) {}

    /**
     * Generate a unique temporary hostname and pass it through the normal domain save/sync operation.
     *
     * @throws WebsiteDomainOperationException If the temporary base domain is unavailable.
     */
    public function handle(Website $website, User $actor, int|string $dnsProviderId): WebsiteDomainSaveResult
    {
        $base = strtolower(trim((string) config('domains.temporary_base_domain')));
        if ($base === '') {
            throw new WebsiteDomainOperationException('Set TEMPORARY_APP_DOMAIN before issuing temporary domains.');
        }

        $prefix = Str::limit($website->deployment_slug, 40, '').'-'.Str::lower(Str::random(8));

        return $this->saveDomain->handle($website, $actor, [
            'dns_provider_id' => $dnsProviderId,
            'hostname' => $prefix.'.'.$base,
            'type' => 'alias',
            'is_temporary' => true,
        ]);
    }
}
