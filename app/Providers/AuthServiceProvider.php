<?php

namespace App\Providers;

use App\Modules\Deployer\Models\AlertDestination;
use App\Modules\Deployer\Models\BackupDestination;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\ConfigurationApplication;
use App\Modules\Deployer\Models\ConfigurationReview;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\EnvironmentResource;
use App\Modules\Deployer\Models\LoadBalancer;
use App\Modules\Deployer\Models\MetricAlertRule;
use App\Modules\Deployer\Models\OperationalIncident;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\PreviewDeployment;
use App\Modules\Deployer\Models\ProductFeedback;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\RecipeReport;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Models\StatusIncident;
use App\Modules\Deployer\Models\StatusPage;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Models\WebsiteBackup;
use App\Modules\Deployer\Models\WebsiteBackupSchedule;
use App\Modules\Deployer\Policies\AlertDestinationPolicy;
use App\Modules\Deployer\Policies\BackupDestinationPolicy;
use App\Modules\Deployer\Policies\BuildPolicy;
use App\Modules\Deployer\Policies\ConfigurationApplicationPolicy;
use App\Modules\Deployer\Policies\ConfigurationReviewPolicy;
use App\Modules\Deployer\Policies\EnvironmentPolicy;
use App\Modules\Deployer\Policies\EnvironmentResourcePolicy;
use App\Modules\Deployer\Policies\LoadBalancerPolicy;
use App\Modules\Deployer\Policies\MetricAlertRulePolicy;
use App\Modules\Deployer\Policies\NotificationPolicy;
use App\Modules\Deployer\Policies\OperationalIncidentPolicy;
use App\Modules\Deployer\Policies\OrganizationPolicy;
use App\Modules\Deployer\Policies\PersonalAccessTokenPolicy;
use App\Modules\Deployer\Policies\PreviewDeploymentPolicy;
use App\Modules\Deployer\Policies\ProductFeedbackPolicy;
use App\Modules\Deployer\Policies\ProjectPolicy;
use App\Modules\Deployer\Policies\ProviderPolicy;
use App\Modules\Deployer\Policies\RecipePolicy;
use App\Modules\Deployer\Policies\RecipeReportPolicy;
use App\Modules\Deployer\Policies\RepositoryPolicy;
use App\Modules\Deployer\Policies\ServerPolicy;
use App\Modules\Deployer\Policies\ServerTroubleshootingSessionPolicy;
use App\Modules\Deployer\Policies\StatusIncidentPolicy;
use App\Modules\Deployer\Policies\StatusPagePolicy;
use App\Modules\Deployer\Policies\WebsiteBackupPolicy;
use App\Modules\Deployer\Policies\WebsiteBackupSchedulePolicy;
use App\Modules\Deployer\Policies\WebsitePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Gate;
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
        DatabaseNotification::class => NotificationPolicy::class,
        OperationalIncident::class => OperationalIncidentPolicy::class,
        Organization::class => OrganizationPolicy::class,
        PersonalAccessToken::class => PersonalAccessTokenPolicy::class,
        Repository::class => RepositoryPolicy::class,
        Recipe::class => RecipePolicy::class,
        RecipeReport::class => RecipeReportPolicy::class,
        ProductFeedback::class => ProductFeedbackPolicy::class,
        Server::class => ServerPolicy::class,
        ServerTroubleshootingSession::class => ServerTroubleshootingSessionPolicy::class,
        PreviewDeployment::class => PreviewDeploymentPolicy::class,
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

        Gate::define('platform-admin', fn (User $user): bool => $user->isPlatformAdmin());

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
