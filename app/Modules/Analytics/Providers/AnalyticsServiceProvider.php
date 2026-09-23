<?php

namespace App\Modules\Analytics\Providers;

use App\Core\Providers\ModuleServiceProvider;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Policies\SitePolicy;
use App\Modules\Analytics\Services\WorkspaceViewData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewInstance;

final class AnalyticsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(app_path('Modules/Analytics/Config/Source/analytics.php'), 'analytics');
    }

    public function boot(): void
    {
        parent::boot();

        if (! config('platform.products.analytics.enabled', false)
            || ! filled(config('platform.products.analytics.host'))) {
            return;
        }

        Gate::policy(Site::class, SitePolicy::class);

        View::composer('analytics::layouts.app', function (ViewInstance $view): void {
            app(WorkspaceViewData::class)->compose($view);
        });

        RateLimiter::for('analytics.collect', function (Request $request): array {
            $limit = config('analytics.collect_rate_per_minute', 120);
            $publicId = (string) $request->route('publicId');

            return [
                Limit::perMinute($limit)->by($request->ip()),
                Limit::perMinute($limit)->by($publicId.'|'.$request->ip()),
            ];
        });
    }

    protected function modulePath(): string
    {
        return 'Modules/Analytics';
    }

    protected function moduleKey(): string
    {
        return 'analytics';
    }

    protected function routeNamePrefix(): string
    {
        return 'analytics.';
    }
}
