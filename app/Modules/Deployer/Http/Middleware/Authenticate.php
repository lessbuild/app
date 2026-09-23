<?php

namespace App\Modules\Deployer\Http\Middleware;

use App\Core\Services\Auth\ProductAuthentication;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    public function __construct(AuthFactory $auth, private readonly ProductAuthentication $productAuthentication)
    {
        parent::__construct($auth);
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  Request  $request
     * @return string|null
     */
    protected function redirectTo(Request $request): ?string
    {
        if (! $request->expectsJson()) {
            $product = $this->productAuthentication->productForRequest($request);

            if ($product !== null && $this->productAuthentication->usesCoreAuthority($product)) {
                return $this->productAuthentication->platformLoginUrl($request, $product);
            }

            if ($request->routeIs('platform.*', 'core.*')) {
                return route('platform.login');
            }

            return route('login');
        }

        return null;
    }
}
