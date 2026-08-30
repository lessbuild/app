<?php

namespace App\Providers;

use App\Models\Build;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\Website;
use App\Observers\BuildActivityObserver;
use App\Observers\ServerCommandExecutionObserver;
use App\Observers\ServerObserver;
use App\Observers\WebsiteObserver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        Build::observe(BuildActivityObserver::class);
        Server::observe(ServerObserver::class);
        ServerCommandExecution::observe(ServerCommandExecutionObserver::class);
        Website::observe(WebsiteObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
