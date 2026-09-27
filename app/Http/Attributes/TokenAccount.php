<?php

declare(strict_types=1);

namespace App\Http\Attributes;

use App\Models\Account;
use Attribute;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;
use Illuminate\Http\Request;

/** Injects the API token's account (set by the `token.account` middleware); without one the request is a 403. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class TokenAccount implements ContextualAttribute
{
    public static function resolve(self $attribute, Container $container): Account
    {
        $account = $container->make(Request::class)->attributes->get('account');

        return $account instanceof Account ? $account : abort(403);
    }
}
