<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Models\Account;
use App\Services\Billing\Entitlements;

/**
 * What clients see on an account's status pages, shared reports and client reports: the agency's own name, logo and
 * colour, without "Powered by", when the account's plan includes white labelling and a brand is set.
 */
final class AccountBranding
{
    /**
     * Create a new AccountBranding instance.
     *
     * @param  Entitlements  $entitlements  Checks the plan includes white labelling.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Get the account's branding, or null to show ours.
     *
     * @param  Account  $account
     * @return array{name: string, logo: string|null, color: string|null}|null
     */
    public function for(Account $account): ?array
    {
        if ($account->brand_name === null || ! $this->entitlements->for($account)->has('account.white_label')) {
            return null;
        }

        return ['name' => $account->brand_name, 'logo' => $account->brand_logo_url, 'color' => $account->brand_color];
    }
}
