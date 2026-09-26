<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum SignInMethod: string
{
    case Password = 'password';
    case Passkey = 'passkey';
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Registration = 'registration';
    /** Signed back in from a "remember me" cookie. */
    case Remembered = 'remembered';

    public static function fromProvider(SocialProvider $provider): self
    {
        return self::from($provider->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Password => __('Password'),
            self::Passkey => __('Passkey'),
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
            self::Registration => __('New account'),
            self::Remembered => __('Remembered browser'),
        };
    }
}
