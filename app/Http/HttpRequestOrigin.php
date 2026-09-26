<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Audit\Contracts\RequestOrigin;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

final class HttpRequestOrigin implements RequestOrigin
{
    public function __construct(private readonly Application $app) {}

    public function ipAddress(): ?string
    {
        return $this->request()?->ip();
    }

    public function userAgent(): ?string
    {
        return $this->request()?->userAgent();
    }

    private function request(): ?Request
    {
        return $this->app->runningInConsole() && ! $this->app->runningUnitTests() ? null : $this->app->make(Request::class);
    }
}
