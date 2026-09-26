<?php

namespace App\Core\Providers;

use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

abstract class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->bootModule();
    }

    abstract protected function modulePath(): string;

    abstract protected function moduleKey(): string;

    protected function routeNamePrefix(): string
    {
        return '';
    }

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
            $commandClasses = $this->commandClasses($commands);

            if ($commandClasses !== []) {
                $this->commands($commandClasses);
            }
        }

        $consoleRoutes = $path.'/Routes/console.php';

        if ($this->app->runningInConsole() && is_file($consoleRoutes)) {
            require $consoleRoutes;
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

            $routeNamePrefix = $this->routeNamePrefix();
            $registerRoutes = static function () use ($routeFile, $middleware, $routeNamePrefix): void {
                $group = Route::middleware($middleware);

                if (filled($routeNamePrefix)) {
                    $group->as($routeNamePrefix);
                }

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

    /** @return list<class-string<Command>> */
    private function commandClasses(string $directory): array
    {
        $appPath = app_path().DIRECTORY_SEPARATOR;
        $classes = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($appPath), -4);
            $class = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

            if (class_exists($class) && is_subclass_of($class, Command::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
