<?php

namespace App\Core\Data\Analytics;

final readonly class AnalyticsSiteInput
{
    public function __construct(public string $name, public string $domain, public string $timezone) {}
}
