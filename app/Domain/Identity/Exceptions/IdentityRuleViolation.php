<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use DomainException;

/** A request that is authorised but breaks an identity invariant; rendered as a validation error. */
final class IdentityRuleViolation extends DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function lastSignInMethod(): self
    {
        return new self('social', __('Set a password or add a passkey before disconnecting your only way to sign in.'));
    }

    public static function identityTaken(): self
    {
        return new self('social', __('That provider account is already connected to a different :app account.', ['app' => config('app.name')]));
    }

    public static function providerAlreadyConnected(string $provider): self
    {
        return new self('social', __('A different :provider account is already connected. Disconnect it first.', ['provider' => $provider]));
    }

    public static function noVerifiedEmail(string $provider): self
    {
        return new self('social', __('Your :provider account must have a verified email address.', ['provider' => $provider]));
    }
}
