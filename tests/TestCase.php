<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\URL;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        if (str_starts_with(static::class, 'Tests\\Modules\\Analytics\\')) {
            URL::forceRootUrl('http://analytics.test');
        }
    }
}
