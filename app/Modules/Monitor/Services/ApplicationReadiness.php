<?php

namespace App\Modules\Monitor\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\ConnectionResolverInterface;

final class ApplicationReadiness
{
    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly CacheRepository $cache,
    ) {}

    public function check(): void
    {
        $this->database->connection()->select('select 1');
        $this->cache->get('beacon:health:readiness');
    }
}
