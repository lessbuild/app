<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Account-owned records in URLs resolve only inside the account the URL is about: the route's project's account, or else
 * the signed-in person's current account. Anything else is a 404 before a controller or policy sees it.
 */
final class RouteBindingServiceProvider extends ServiceProvider
{
    /** Only these routes; other routes use the same parameter names for other things (e.g. `/auth/{provider}`). */
    private const ROUTES = ['infrastructure.*', 'account.providers*'];

    public function boot(): void
    {
        foreach (['provider' => Provider::class, 'server' => Server::class, 'website' => Website::class] as $parameter => $model) {
            Route::bind($parameter, fn (string $value, RoutingRoute $route): Model|string => ! $route->named(self::ROUTES) ? $value : $model::query()
                ->where('account_id', $this->accountId($route))->findOrFail(ctype_digit($value) ? (int) $value : 0));
        }
    }

    private function accountId(RoutingRoute $route): string
    {
        $project = $route->parameter('project');
        if ($project instanceof Project) {
            return $project->account_id;
        }
        if (is_string($project)) {
            return Project::query()->whereKey($project)->value('account_id') ?? abort(404);
        }
        $user = Auth::user();

        return ($user instanceof User ? $user->current_account_id : null) ?? abort(404);
    }
}
