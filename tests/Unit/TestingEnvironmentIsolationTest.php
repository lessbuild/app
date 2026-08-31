<?php

namespace Tests\Unit;

use Tests\TestCase;

class TestingEnvironmentIsolationTest extends TestCase
{
    public function test_testing_environment_is_isolated_from_production_configuration(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('session.driver'));
    }

    public function test_testing_environment_uses_dedicated_framework_cache_paths(): void
    {
        $testingCachePaths = [
            $this->app->getCachedConfigPath() => 'config-testing.php',
            $this->app->getCachedEventsPath() => 'events-testing.php',
            $this->app->getCachedPackagesPath() => 'packages-testing.php',
            $this->app->getCachedRoutesPath() => 'routes-testing.php',
            $this->app->getCachedServicesPath() => 'services-testing.php',
        ];

        foreach ($testingCachePaths as $path => $filename) {
            $this->assertSame(base_path("bootstrap/cache/{$filename}"), $path);
        }

        $this->assertFalse($this->app->configurationIsCached());
    }
}
