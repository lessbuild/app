<?php

namespace App\Modules\Monitor\Listeners;

use App\Modules\Monitor\Services\ApplicationReadiness;
use Illuminate\Foundation\Events\DiagnosingHealth;

final class CheckApplicationHealth
{
    public function __construct(private readonly ApplicationReadiness $readiness) {}

    public function handle(DiagnosingHealth $event): void
    {
        $this->readiness->check();
    }
}
