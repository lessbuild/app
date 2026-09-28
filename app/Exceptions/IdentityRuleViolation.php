<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A request that is authorised but breaks an identity invariant; rendered as a validation error. */
final class IdentityRuleViolation extends RuleViolation
{
    /**
     * Disconnecting this provider would leave the person with no password, passkey or other provider to sign in with.
     *
     * @return IdentityRuleViolation
     */
    public static function lastSignInMethod(): self
    {
        return new self('social', __('Set a password or add a passkey before disconnecting your only way to sign in.'));
    }

    /**
     * The provider account being connected already belongs to another user here.
     *
     * @return IdentityRuleViolation
     */
    public static function identityTaken(): self
    {
        return new self('social', __('That provider account is already connected to a different :app account.', ['app' => config('app.name')]));
    }

    /**
     * The person already has a different account from this provider connected; only one per provider is allowed.
     *
     * @param  string  $provider
     * @return IdentityRuleViolation
     */
    public static function providerAlreadyConnected(string $provider): self
    {
        return new self('social', __('A different :provider account is already connected. Disconnect it first.', ['provider' => $provider]));
    }

    /**
     * The provider didn't vouch for the account's email address, so we can't trust it to identify the person.
     *
     * @param  string  $provider
     * @return IdentityRuleViolation
     */
    public static function noVerifiedEmail(string $provider): self
    {
        return new self('social', __('Your :provider account must have a verified email address.', ['provider' => $provider]));
    }
}
