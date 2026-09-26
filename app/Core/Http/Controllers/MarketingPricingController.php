<?php

namespace App\Core\Http\Controllers;

use Illuminate\Contracts\View\View;

final class MarketingPricingController
{
    public function __invoke(): View
    {
        return view('core::marketing.pricing', [
            'deployerPlans' => config('billing.plans', []),
            'monitorPlans' => config('monitor.beacon.plans', []),
        ]);
    }
}
