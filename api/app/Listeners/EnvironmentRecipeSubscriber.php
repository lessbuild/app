<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Infrastructure\WebsiteProvisioned;
use App\Jobs\Deploy\RunEnvironmentRecipes;
use App\Models\Environment;
use App\Models\Repository;
use Illuminate\Events\Dispatcher;

/** Runs an environment's recipes on a new website's server when the environment asks for that. */
final class EnvironmentRecipeSubscriber
{
    /**
     * Register for websites finishing their setup.
     *
     * @param  Dispatcher  $events
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [WebsiteProvisioned::class => 'provisioned'];
    }

    /**
     * Queue the recipes of each environment the website belongs to (its own, or its repositories') that runs recipes
     * on new websites.
     *
     * @param  WebsiteProvisioned  $event
     * @return void
     */
    public function provisioned(WebsiteProvisioned $event): void
    {
        $website = $event->website;
        if ($website->server_id === null) {
            return;
        }
        Environment::query()->where('recipes_run_on_new_websites', true)
            ->where(fn ($query) => $query->whereKey($website->environment_id)->orWhereIn('id', Repository::query()->where('website_id', $website->id)->whereNotNull('environment_id')->select('environment_id')))
            ->whereHas('recipes')->pluck('id')
            ->each(fn (string $id) => RunEnvironmentRecipes::dispatch($id, (int) $website->server_id));
    }
}
