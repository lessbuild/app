<?php

namespace App\Providers;

use App\Http\Livewire\BuildDeploymentStatus;
use App\Http\Livewire\RepositorySetup;
use App\Http\Livewire\ServerCommand;
use App\Http\Livewire\ServerSetup;
use App\Http\Livewire\ServerShow;
use App\Http\Livewire\WebsiteProvisioningLog;
use App\Http\Livewire\WebsiteSetup;
use App\Jobs\SyncOrganizationSeatQuantityJob;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Cashier::useCustomerModel(User::class);
        Event::listen(WebhookHandled::class, function (WebhookHandled $event): void {
            $object = data_get($event->payload, 'data.object', []);
            $organizationId = data_get($object, 'metadata.organization_id');
            if (! $organizationId && ($customer = data_get($object, 'customer'))) {
                $organizationId = Organization::query()->whereHas('owner', fn ($query) => $query->where('stripe_id', $customer))->value('id');
            }
            if ($organizationId) {
                SyncOrganizationSeatQuantityJob::dispatch((int) $organizationId);
            }
        });

        DB::prohibitDestructiveCommands(
            (bool) config('lessbuild.prohibit_destructive_database_commands'),
        );

        Livewire::component('build-deployment-status', BuildDeploymentStatus::class);
        Livewire::component('repository-setup', RepositorySetup::class);
        Livewire::component('server-command', ServerCommand::class);
        Livewire::component('server-setup', ServerSetup::class);
        Livewire::component('server-show', ServerShow::class);
        Livewire::component('website-setup', WebsiteSetup::class);
        Livewire::component('website-provisioning-log', WebsiteProvisioningLog::class);
    }
}
