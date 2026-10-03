<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsFunnel;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveFunnel
{
    /**
     * Create or update a funnel with two to six steps. Empty step rows are skipped; pages must start with "/".
     *
     * @param  User  $actor
     * @param  AnalyticsSite  $site
     * @param  AnalyticsFunnel|null  $funnel  null to create one
     * @param  string  $name
     * @param  list<array{kind?: mixed, match?: mixed, value?: mixed}>  $rows
     * @return AnalyticsFunnel
     */
    public function handle(User $actor, AnalyticsSite $site, ?AnalyticsFunnel $funnel, string $name, array $rows): AnalyticsFunnel
    {
        Gate::forUser($actor)->authorize('update', $site);
        $steps = [];
        foreach ($rows as $row) {
            $value = trim(is_string($row['value'] ?? null) ? $row['value'] : '');
            if ($value === '') {
                continue;
            }
            $kind = ($row['kind'] ?? null) === 'event' ? 'event' : 'pageview';
            if ($kind === 'pageview' && ! str_starts_with($value, '/')) {
                throw ValidationException::withMessages(['steps' => __('Page steps start with /, such as /cart.')]);
            }
            $steps[] = ['kind' => $kind, 'match' => $kind === 'pageview' && ($row['match'] ?? null) === 'prefix' ? 'prefix' : 'exact', 'value' => mb_substr($value, 0, 255)];
        }
        if (count($steps) < 2 || count($steps) > 6) {
            throw ValidationException::withMessages(['steps' => __('A funnel has two to six steps.')]);
        }
        $funnel ??= new AnalyticsFunnel;
        $funnel->forceFill(['site_id' => $site->id, 'name' => trim($name), 'steps' => $steps])->save();

        return $funnel;
    }
}
