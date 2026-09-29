<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Enums\AlertDestinationType;
use App\Models\AlertDestination;
use App\Models\Environment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UpdateEnvironmentDeployNotifications
{
    /**
     * The outcomes a destination can hear about, as their columns.
     *
     * @var list<string>
     */
    public const OUTCOMES = ['on_success', 'on_failure', 'on_approval'];

    /**
     * Choose which of the account's alert destinations hear about the environment's deploys, and for which outcomes.
     * A destination with no outcome ticked stops hearing about them. PagerDuty and phone calls are left out: they page
     * people, and a deploy isn't an incident.
     *
     * @param  User  $actor
     * @param  Environment  $environment
     * @param  array<int|string, mixed>  $choices  destination id => list of outcome columns
     * @return int how many destinations now hear about deploys
     */
    public function handle(User $actor, Environment $environment, array $choices): int
    {
        Gate::forUser($actor)->authorize('configureDeploy', $environment);
        $destinations = AlertDestination::query()->forAccount($environment->project->account)
            ->whereNotIn('type', [AlertDestinationType::PagerDuty, AlertDestinationType::Voice])->get();

        $routed = 0;
        foreach ($destinations as $destination) {
            $chosen = $choices[$destination->id] ?? [];
            $flags = [];
            foreach (self::OUTCOMES as $outcome) {
                $flags[$outcome] = is_array($chosen) && in_array($outcome, $chosen, true);
            }
            if (! in_array(true, $flags, true)) {
                $environment->deployNotifications()->where('alert_destination_id', $destination->id)->delete();

                continue;
            }
            $route = $environment->deployNotifications()->where('alert_destination_id', $destination->id)->first()
                ?? $environment->deployNotifications()->make()->forceFill(['alert_destination_id' => $destination->id]);
            $route->forceFill($flags)->save();
            $routed++;
        }

        return $routed;
    }
}
