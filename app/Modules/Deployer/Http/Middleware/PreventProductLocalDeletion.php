<?php

namespace App\Modules\Deployer\Http\Middleware;

use App\Core\Services\Auth\ProductAuthentication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PreventProductLocalDeletion
{
    public function __construct(private readonly ProductAuthentication $authentication) {}

    public function handle(Request $request, Closure $next, string $resource): Response
    {
        if (! $this->authentication->usesCoreAuthority('deployer')) {
            return $next($request);
        }

        abort(409, match ($resource) {
            'account' => __('Shared account deletion is unavailable until Core, Deployer, Monitor, and Analytics cleanup can be coordinated. No data was changed.'),
            'workspace' => __('Shared workspace deletion is unavailable until Core, Deployer, Monitor, and Analytics cleanup can be coordinated. No data was changed.'),
            default => __('This deletion is unavailable until shared product cleanup can be coordinated. No data was changed.'),
        });
    }
}
