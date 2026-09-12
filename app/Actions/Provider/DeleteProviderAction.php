<?php

namespace App\Actions\Provider;

use App\Exceptions\ProviderOperationException;
use App\Models\Provider;

class DeleteProviderAction
{
    /**
     * Soft-delete an unused provider connection after protecting attached resources.
     *
     * @throws ProviderOperationException When servers, repositories or DNS domains remain attached.
     */
    public function handle(Provider $provider): void
    {
        if ($provider->hasAttachedResources()) {
            throw new ProviderOperationException('provider', __('Detach or delete this provider’s servers and repositories first.'));
        }

        $provider->delete();
    }
}
