<?php

declare(strict_types=1);

namespace App\Http\Attributes;

use App\Models\Account;
use App\Models\User;
use Attribute;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;

/** Injects the signed-in person's current account; a request without one is a 404. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class CurrentAccount implements ContextualAttribute
{
    /**
     * The signed-in person's current account; 404 without one.
     *
     * @param  CurrentAccount  $attribute
     * @param  Container  $container
     * @return Account
     */
    public static function resolve(self $attribute, Container $container): Account
    {
        $user = $container->make('auth')->user();

        return ($user instanceof User ? $user->currentAccount : null) ?? abort(404);
    }
}
