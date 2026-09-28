<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Enums\ServerType;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\ProvidersQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Infrastructure\ServerCatalog;
use App\Services\Infrastructure\ServerProviderResolver;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Throwable;

/** Pick a provider, then its region, size and Ubuntu image (read live from the provider). */
final class CreateServerController
{
    /**
     * Show the new server form. The chosen provider's regions, sizes and images are read live; if that fails, the form
     * says so instead of breaking.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProvidersQuery  $providers
     * @param  ServerProviderResolver  $resolver
     * @param  ServerCatalog  $catalogs
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProvidersQuery $providers, ServerProviderResolver $resolver, ServerCatalog $catalogs): View
    {
        $choices = $providers->serverHosts($project->account_id);
        $selected = $choices->firstWhere('id', (int) $request->query('provider', (string) ($choices->first()->id ?? 0)));
        $catalog = null;
        $catalogError = null;
        if ($selected !== null) {
            try {
                $catalog = $catalogs->for($selected, $resolver->resolve($selected));
            } catch (Throwable $exception) {
                report($exception);
                $catalogError = __('Couldn’t load :provider’s regions and sizes. Check the provider’s token and try again.', ['provider' => $selected->name]);
            }
        }

        return view('infrastructure.server-create', [
            'overview' => $overview->handle($project, $user),
            'providers' => $choices,
            'provider' => $selected,
            'catalog' => $catalog,
            'catalogError' => $catalogError,
            'types' => ServerType::cases(),
        ]);
    }
}
