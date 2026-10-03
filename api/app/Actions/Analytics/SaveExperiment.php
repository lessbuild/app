<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Exceptions\AccountRuleViolation;
use App\Models\AnalyticsExperiment;
use App\Models\AnalyticsGoal;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class SaveExperiment
{
    /**
     * Start an A/B test on a site: a key the page uses, two to five variants (the first is the control) and the goal
     * that decides it.
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  string  $name
     * @param  string  $key
     * @param  list<string>  $variants
     * @param  int|null  $goalId
     * @return AnalyticsExperiment
     */
    public function handle(User $actor, AnalyticsSite $site, string $name, string $key, array $variants, ?int $goalId): AnalyticsExperiment
    {
        Gate::forUser($actor)->authorize('update', $site);
        $key = Str::slug($key);
        $variants = array_values(array_unique(array_filter(array_map(fn (string $variant): string => mb_substr(trim($variant), 0, 60), $variants))));
        if ($key === '' || count($variants) < 2 || count($variants) > 5) {
            throw new AccountRuleViolation('variants', __('Give the experiment a key and two to five variants.'));
        }
        if (AnalyticsExperiment::query()->where('site_id', $site->id)->where('key', $key)->exists()) {
            throw new AccountRuleViolation('key', __('This site already has an experiment called :key.', ['key' => $key]));
        }
        if ($goalId !== null && ! AnalyticsGoal::query()->where('site_id', $site->id)->whereKey($goalId)->exists()) {
            throw new AccountRuleViolation('goal_id', __('Choose one of this site’s goals.'));
        }
        $experiment = new AnalyticsExperiment;
        $experiment->forceFill(['site_id' => $site->id, 'key' => $key, 'name' => mb_substr(trim($name), 0, 120), 'variants' => $variants, 'goal_id' => $goalId, 'status' => 'running', 'started_at' => now()])->save();

        return $experiment;
    }
}
