<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'stripe/*',
        'servers/*/provisioning/callback/status',
        'servers/*/provisioning/callback/failed',
        'servers/*/provisioning/callback/log',
        'websites/*/provisioning/callback/status',
        'websites/*/provisioning/callback/failed',
        'websites/*/provisioning/callback/log',
        'builds/*/deployment/callback/status',
        'builds/*/deployment/callback/failed',
        'builds/*/deployment/callback/log',
    ];
}
