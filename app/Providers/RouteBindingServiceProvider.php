<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use App\Models\AlertRule;
use App\Models\AnalyticsSite;
use App\Models\BackupDestination;
use App\Models\Dashboard;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Incident;
use App\Models\IngestReceipt;
use App\Models\IngestToken;
use App\Models\Issue;
use App\Models\LoadBalancer;
use App\Models\MaintenanceWindow;
use App\Models\MetricSeries;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Release;
use App\Models\Server;
use App\Models\ServiceLevelObjective;
use App\Models\StatusPage;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Records in URLs resolve only inside what the URL is about: the route's project (directly or through its environments),
 * or the account (the project's, else the signed-in person's current account). Anything else is a 404 before a controller
 * or policy sees it. Archived records resolve on their `.show` page only.
 */
final class RouteBindingServiceProvider extends ServiceProvider
{
    /**
     * Parameter => [model, scope, route names]. Scope is 'account', 'project' (a project_id column) or 'environment'
     * (an environment_id in the project). The route filter matters: other routes reuse names like `{provider}`.
     *
     * @var array<string, array{class-string<Model>, 'account'|'project'|'environment', list<string>}>
     */
    private const BINDINGS = [
        'provider' => [Provider::class, 'account', ['account.providers*']],
        'server' => [Server::class, 'account', ['infrastructure.*']],
        'website' => [Website::class, 'account', ['infrastructure.*']],
        'backupDestination' => [BackupDestination::class, 'account', ['infrastructure.*']],
        'loadBalancer' => [LoadBalancer::class, 'account', ['infrastructure.*']],
        'destination' => [AlertDestination::class, 'account', ['monitoring.*']],
        'window' => [MaintenanceWindow::class, 'account', ['monitoring.*']],
        'page' => [StatusPage::class, 'account', ['monitoring.*']],
        'dashboard' => [Dashboard::class, 'account', ['monitoring.*']],
        'delivery' => [AlertDelivery::class, 'account', ['monitoring.*']],
        'site' => [AnalyticsSite::class, 'project', ['analytics.*']],
        'incident' => [Incident::class, 'project', ['monitoring.*']],
        'issue' => [Issue::class, 'project', ['monitoring.*']],
        'release' => [Release::class, 'project', ['monitoring.*']],
        'monitor' => [Monitor::class, 'environment', ['monitoring.*']],
        'rule' => [AlertRule::class, 'environment', ['monitoring.*']],
        'objective' => [ServiceLevelObjective::class, 'environment', ['monitoring.*']],
        'token' => [IngestToken::class, 'environment', ['monitoring.*']],
        'series' => [MetricSeries::class, 'environment', ['monitoring.*']],
        'event' => [TelemetryEvent::class, 'environment', ['monitoring.*']],
        'deployment' => [Deployment::class, 'environment', ['monitoring.*']],
        'receipt' => [IngestReceipt::class, 'environment', ['monitoring.*']],
    ];

    public function boot(): void
    {
        foreach (self::BINDINGS as $parameter => [$model, $scope, $routes]) {
            Route::bind($parameter, fn (string $value, RoutingRoute $route): Model|string => $route->named($routes) ? $this->resolve($model, $scope, $value, $route) : $value);
        }
    }

    /** @param class-string<Model> $model */
    private function resolve(string $model, string $scope, string $value, RoutingRoute $route): Model
    {
        $query = $model::query();
        if ($route->named('*.show') && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }
        match ($scope) {
            'account' => $query->where('account_id', $this->accountId($route)),
            'project' => $query->where('project_id', $this->project($route)->id),
            default => $query->whereIn('environment_id', Environment::query()->where('project_id', $this->project($route)->id)->select('id')),
        };
        $key = (new $model)->getKeyType() === 'int' ? (ctype_digit($value) ? (int) $value : 0) : $value;

        return $query->whereKey($key)->firstOr(fn () => abort(404));
    }

    private function project(RoutingRoute $route): Project
    {
        $project = $route->parameter('project');

        return $project instanceof Project ? $project : (Project::query()->find(is_string($project) ? $project : '') ?? abort(404));
    }

    private function accountId(RoutingRoute $route): string
    {
        if ($route->parameter('project') !== null) {
            return $this->project($route)->account_id;
        }
        $user = Auth::user();

        return ($user instanceof User ? $user->current_account_id : null) ?? abort(404);
    }
}
