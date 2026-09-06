<?php

namespace App\Enums;

enum SignInMethod: string
{
    case Password = 'password';
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';

    /** @var list<string> Accepted persisted sign-in methods, in display order. */
    public const array VALUES = [
        self::Password->value,
        self::GitHub->value,
        self::GitLab->value,
        self::Bitbucket->value,
    ];
}
