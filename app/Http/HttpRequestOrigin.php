<?php

declare(strict_types=1);

namespace App\Http;

use App\Contracts\RequestOrigin;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

final class HttpRequestOrigin implements RequestOrigin
{
    /**
     * Reads the request's origin for audit entries.
     *
     * @param  Application  $app  Used to tell console runs from requests and to reach the current request.
     */
    public function __construct(private readonly Application $app) {}

    /**
     * The request's client IP; null in console commands and queued jobs.
     */
    public function ipAddress(): ?string
    {
        return $this->request()?->ip();
    }

    /**
     * The request's user agent; null in console commands and queued jobs.
     */
    public function userAgent(): ?string
    {
        return $this->request()?->userAgent();
    }

    /**
     * The current request, or null outside one. Tests count as requests, so audit entries in tests carry an origin.
     */
    private function request(): ?Request
    {
        return $this->app->runningInConsole() && ! $this->app->runningUnitTests() ? null : $this->app->make(Request::class);
    }
}
