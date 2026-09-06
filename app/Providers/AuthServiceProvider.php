<?php

namespace App\Providers;

use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use App\Policies\BuildPolicy;
use App\Policies\EnvironmentPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProviderPolicy;
use App\Policies\RecipePolicy;
use App\Policies\RepositoryPolicy;
use App\Policies\ServerPolicy;
use App\Policies\WebsitePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Build::class => BuildPolicy::class,
        Provider::class => ProviderPolicy::class,
        Project::class => ProjectPolicy::class,
        Environment::class => EnvironmentPolicy::class,
        Repository::class => RepositoryPolicy::class,
        Recipe::class => RecipePolicy::class,
        Server::class => ServerPolicy::class,
        Website::class => WebsitePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        //
    }
}
