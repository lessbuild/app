<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\MetricSeries;
use App\Modules\Monitor\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class MetricSeriesPolicy
{
    public function view(User $user, MetricSeries $metricSeries): Response
    {
        return $metricSeries->environment === null
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('view', $metricSeries->environment);
    }
}
