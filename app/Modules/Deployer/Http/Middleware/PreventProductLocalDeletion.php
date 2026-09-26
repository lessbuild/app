<?php

namespace App\Modules\Deployer\Http\Middleware;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

final class PreventProductLocalDeletion
{
    public function __construct(
        private readonly ProductAuthentication $authentication,
        private readonly LegacyIdentityResolver $identities,
    ) {}

    public function handle(Request $request, Closure $next, string $resource): Response
    {
        if (! $this->authentication->usesCoreAuthority('deployer')) {
            return $next($request);
        }

        $routeName = match ($resource) {
            'account' => 'platform.deletions.account.create',
            'workspace' => 'platform.deletions.workspace.create',
            default => null,
        };
        if ($routeName !== null && Route::has($routeName)) {
            if ($resource === 'account') {
                $actor = $request->attributes->get('platform_user');
                $user = $request->user();
                $canonicalId = $actor instanceof PlatformUser
                    ? (string) $actor->getKey()
                    : ($user instanceof User ? $this->identities->canonicalIdForSource('deployer', 'user', $user->getKey()) : null);

                abort_unless($canonicalId !== null, 404);

                abort_unless(! ($actor instanceof PlatformUser) || (string) $actor->getKey() === $canonicalId, 404);

                return redirect()->route($routeName);
            }

            $workspace = $request->route('organization');
            $workspace = $workspace instanceof Organization ? $workspace : $request->user()?->currentOrganization;
            abort_unless($workspace instanceof Organization, 404);
            $canonicalWorkspace = $this->identities->canonicalIdForSource('deployer', 'organization', $workspace->getKey(), 'workspace');
            abort_unless($canonicalWorkspace !== null, 404);

            return redirect()->route($routeName, ['workspace' => $canonicalWorkspace]);
        }

        abort(409, match ($resource) {
            'account' => __('Shared account deletion is unavailable until Core, Deployer, Monitor, and Analytics cleanup can be coordinated. No data was changed.'),
            'workspace' => __('Shared workspace deletion is unavailable until Core, Deployer, Monitor, and Analytics cleanup can be coordinated. No data was changed.'),
            default => __('This deletion is unavailable until shared product cleanup can be coordinated. No data was changed.'),
        });
    }
}
