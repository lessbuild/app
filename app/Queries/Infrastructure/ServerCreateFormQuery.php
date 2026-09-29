<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Enums\ServerType;
use App\Models\Project;
use App\Models\Recipe;
use App\Services\Infrastructure\ServerCatalog;
use App\Services\Infrastructure\ServerProviderResolver;
use Throwable;

final class ServerCreateFormQuery
{
    /**
     * Create a new ServerCreateFormQuery instance.
     *
     * @param  ProvidersQuery  $providers  The account's providers that can host servers.
     * @param  ServerProviderResolver  $resolver  Talks to a provider.
     * @param  ServerCatalog  $catalogs  Reads a provider's regions, sizes and images.
     */
    public function __construct(
        private readonly ProvidersQuery $providers,
        private readonly ServerProviderResolver $resolver,
        private readonly ServerCatalog $catalogs,
    ) {}

    /**
     * Gather what the new server form needs: the providers, the chosen one (else the first) with its regions, sizes
     * and images read live, server types and the account's recipes. If the provider can't be read the form says so
     * instead of breaking.
     *
     * @param  Project  $project
     * @param  string|int|null  $providerId
     * @return array<string, mixed>
     */
    public function handle(Project $project, string|int|null $providerId): array
    {
        $choices = $this->providers->serverHosts($project->account_id);
        $selected = $choices->firstWhere('id', (int) ($providerId ?? ($choices->first()->id ?? 0)));
        $catalog = null;
        $catalogError = null;
        if ($selected !== null) {
            try {
                $catalog = $this->catalogs->for($selected, $this->resolver->resolve($selected));
            } catch (Throwable $exception) {
                report($exception);
                $catalogError = __('Couldn’t load :provider’s regions and sizes. Check the provider’s token and try again.', ['provider' => $selected->name]);
            }
        }

        return [
            'providers' => $choices,
            'provider' => $selected,
            'catalog' => $catalog,
            'catalogError' => $catalogError,
            'types' => ServerType::cases(),
            'recipes' => Recipe::query()->where('account_id', $project->account_id)->orderBy('name')->get(['id', 'name', 'description', 'category']),
        ];
    }
}
