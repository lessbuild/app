<?php

namespace App\Providers;

use App\Modules\Deployer\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        Telescope::filter(function (IncomingEntry $entry): bool {
            // Credentials and destination URLs can appear in view data, request
            // bodies, and model events. Do not persist any entry from these flows,
            // including failed validation and local development requests.
            if ($this->sensitiveOperation()) {
                return false;
            }
            if ($this->app->environment('local')) {
                return true;
            }

            return $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     *
     * @return void
     */
    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters([
            '_token', 'password', 'password_confirmation', 'current_password',
            'token', 'secret', 'signing_secret', 'endpoint_url', 'verification_token',
            'definition', 'definition_json',
        ]);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'authorization',
        ]);
    }

    private function sensitiveOperation(): bool
    {
        return $this->app->bound('request') && $this->app['request']->routeIs(
            'core.workspace.credentials.*',
            'core.workspace.monitor.destinations.*',
            'core.workspace.blueprints.*',
            'core.workspace.analytics.sites.verify',
        );
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     *
     * @return void
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function (User $user): bool {
            return $user->isPlatformAdmin();
        });
    }
}
