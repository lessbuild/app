<?php

declare(strict_types=1);

namespace App\Queries\Deploy;

use App\Enums\ProviderType;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Website;
use Illuminate\Database\Eloquent\Collection;

/** The choices on the repository form: the account's Git providers and websites, and the project's environments. */
final class RepositoryFormQuery
{
    /**
     * Get the choices on the repository form: the account's Git providers, its websites, and the project's
     * environments.
     *
     * @param  Project  $project
     * @return array{providers: Collection<int, Provider>, websites: Collection<int, Website>, environments: Collection<int, \App\Models\Environment>}
     */
    public function handle(Project $project): array
    {
        return [
            'providers' => Provider::query()->where('account_id', $project->account_id)->whereIn('type', [ProviderType::GitHub, ProviderType::GitLab, ProviderType::Bitbucket])->orderBy('name')->get(),
            'websites' => Website::query()->where('account_id', $project->account_id)->orderBy('name')->get(),
            'environments' => $project->environments()->orderBy('name')->get(),
        ];
    }
}
