<?php

namespace App\Actions\Organization;

use App\Data\EnterpriseSsoCallbackData;
use App\Models\Organization;
use App\Models\User;
use App\Services\EnterpriseOidc;
use App\Services\Entitlements;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;
use Throwable;

class VerifyEnterpriseSsoAction
{
    public function __construct(
        private readonly EnterpriseOidc $oidc,
        private readonly Entitlements $entitlements,
        private readonly Session $session,
    ) {}

    /**
     * Recheck the SSO entitlement and verify the callback against the current user's configured identity provider.
     *
     * Remote and protocol failures are reported internally and exposed through the existing generic SSO error.
     *
     * @throws ValidationException If the workspace is not entitled or provider verification fails.
     */
    public function handle(User $actor, Organization $organization, EnterpriseSsoCallbackData $data): void
    {
        $this->entitlements->enforce($organization, 'sso');

        try {
            $this->oidc->verify($data, $organization, $actor, $this->session);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'sso' => __('SSO verification failed. Please try again or contact a workspace administrator.'),
            ]);
        }
    }
}
