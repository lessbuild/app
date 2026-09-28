<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';

    /**
     * The provider's name for sign-in buttons and connected-account lists.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
        };
    }
}
