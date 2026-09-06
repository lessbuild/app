<?php

namespace App\Presenters;

use App\Enums\SignInMethod;

final class SignInEventPresenter
{
    /**
     * @param  string  $method  The stored sign-in method, including historical unknown values.
     * @return string A translated password/unknown label or the provider's display name.
     */
    public static function methodLabel(string $method): string
    {
        return match (SignInMethod::tryFrom($method)) {
            SignInMethod::Password => __('Password'),
            SignInMethod::GitHub => 'GitHub',
            SignInMethod::GitLab => 'GitLab',
            SignInMethod::Bitbucket => 'Bitbucket',
            default => __('Unknown'),
        };
    }
}
