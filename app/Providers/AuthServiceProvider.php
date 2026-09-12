<?php

namespace App\Providers;

use App\Models\AlertDestination;
use App\Models\BackupDestination;
use App\Models\Build;
use App\Models\ConfigurationApplication;
use App\Models\ConfigurationReview;
use App\Models\Environment;
use App\Models\EnvironmentResource;
use App\Models\LoadBalancer;
use App\Models\MetricAlertRule;
use App\Models\OperationalIncident;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\Repository;
use App\Models\Server;
use App\Models\StatusIncident;
use App\Models\StatusPage;
use App\Models\Website;
use App\Models\WebsiteBackup;
use App\Models\WebsiteBackupSchedule;
use App\Policies\AlertDestinationPolicy;
use App\Policies\BackupDestinationPolicy;
use App\Policies\BuildPolicy;
use App\Policies\ConfigurationApplicationPolicy;
use App\Policies\ConfigurationReviewPolicy;
use App\Policies\EnvironmentPolicy;
use App\Policies\EnvironmentResourcePolicy;
use App\Policies\LoadBalancerPolicy;
use App\Policies\MetricAlertRulePolicy;
use App\Policies\OperationalIncidentPolicy;
use App\Policies\PersonalAccessTokenPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProviderPolicy;
use App\Policies\RecipePolicy;
use App\Policies\RepositoryPolicy;
use App\Policies\ServerPolicy;
use App\Policies\StatusIncidentPolicy;
use App\Policies\StatusPagePolicy;
use App\Policies\WebsiteBackupPolicy;
use App\Policies\WebsiteBackupSchedulePolicy;
use App\Policies\WebsitePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Laravel\Sanctum\PersonalAccessToken;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        AlertDestination::class => AlertDestinationPolicy::class,
        Build::class => BuildPolicy::class,
        BackupDestination::class => BackupDestinationPolicy::class,
        ConfigurationApplication::class => ConfigurationApplicationPolicy::class,
        ConfigurationReview::class => ConfigurationReviewPolicy::class,
        Provider::class => ProviderPolicy::class,
        Project::class => ProjectPolicy::class,
        Environment::class => EnvironmentPolicy::class,
        EnvironmentResource::class => EnvironmentResourcePolicy::class,
        LoadBalancer::class => LoadBalancerPolicy::class,
        MetricAlertRule::class => MetricAlertRulePolicy::class,
        OperationalIncident::class => OperationalIncidentPolicy::class,
        PersonalAccessToken::class => PersonalAccessTokenPolicy::class,
        Repository::class => RepositoryPolicy::class,
        Recipe::class => RecipePolicy::class,
        Server::class => ServerPolicy::class,
        StatusPage::class => StatusPagePolicy::class,
        StatusIncident::class => StatusIncidentPolicy::class,
        Website::class => WebsitePolicy::class,
        WebsiteBackup::class => WebsiteBackupPolicy::class,
        WebsiteBackupSchedule::class => WebsiteBackupSchedulePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->registerPolicies();

        AuthenticateSession::redirectUsing($this->sessionLoginRedirect(...));

        //
    }

    /**
     * Send revoked browser sessions to login while retaining JSON authentication errors.
     */
    private function sessionLoginRedirect(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('login');
    }
}
