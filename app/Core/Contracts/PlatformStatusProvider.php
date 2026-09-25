<?php

namespace App\Core\Contracts;

interface PlatformStatusProvider
{
    /**
     * @return list<array{name: string, description: string, operational: bool}>
     */
    public function components(): array;
}
