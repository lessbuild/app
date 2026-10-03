<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsAnnotation;
use App\Models\AnalyticsSite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

final class AddAnnotation
{
    /**
     * Add a note to a site's chart on a day.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $date
     * @param  string  $text
     * @return AnalyticsAnnotation
     */
    public function handle(User $actor, AnalyticsSite $site, CarbonImmutable $date, string $text): AnalyticsAnnotation
    {
        Gate::forUser($actor)->authorize('update', $site);
        $annotation = new AnalyticsAnnotation;
        $annotation->forceFill(['site_id' => $site->id, 'created_by' => $actor->id, 'date' => $date->toDateString(), 'text' => mb_substr(trim($text), 0, 200)])->save();

        return $annotation;
    }
}
