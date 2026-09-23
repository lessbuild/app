<?php

namespace App\Core\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

abstract class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->bootModule();
    }

    abstract protected function modulePath(): string;

    abstract protected function moduleKey(): string;

    protected function bootModule(): void
    {
        $path = app_path($this->modulePath());
        $views = $path.'/Views';

        // Module migrations are invoked with platform:migrate so each module
        // gets an isolated migration path and migration repository.

        if (is_dir($views)) {
            $this->loadViewsFrom($views, $this->moduleKey());
        }

        if ($this->moduleKey() !== 'core') {
            $product = config('platform.products.'.$this->moduleKey(), []);

            if (! ($product['enabled'] ?? false)) {
                return;
            }

            if ($this->moduleKey() !== 'deployer' && ! filled($product['host'] ?? null)) {
                return;
            }
        }

        $commands = $path.'/Console/Commands';

        if ($this->app->runningInConsole() && is_dir($commands)) {
            $this->commands($commands);
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        $routes = $path.'/Routes';

        foreach (['web' => 'web', 'api' => 'api'] as $file => $middleware) {
            $routeFile = $routes.'/'.$file.'.php';

            if (! is_file($routeFile)) {
                continue;
            }

            $registerRoutes = static function () use ($routeFile, $middleware): void {
                $group = Route::middleware($middleware);

                if ($middleware === 'api') {
                    $group->prefix('api');
                }

                $group->group($routeFile);
            };

            $host = $this->routeHost($file);

            if (filled($host)) {
                Route::domain($host)->group($registerRoutes);
            } else {
                $registerRoutes();
            }
        }
    }

    protected function routeHost(string $file): ?string
    {
        return config('platform.products.'.$this->moduleKey().'.host');
    }
}
