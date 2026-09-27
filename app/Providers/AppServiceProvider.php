<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\DnsResolver;
use App\Contracts\Monitoring\DnsRecordResolver;
use App\Contracts\Monitoring\DnsResolver as MonitoringDnsResolver;
use App\Contracts\Monitoring\TcpConnector;
use App\Contracts\Monitoring\TlsCertificateInspector;
use App\Contracts\PaymentProvider;
use App\Contracts\RequestOrigin;
use App\Contracts\Telemetry\TelemetryIngestor;
use App\Contracts\Telemetry\TelemetryPayloadMapper;
use App\Http\HttpRequestOrigin;
use App\Http\View\ShellComposer;
use App\Listeners\AuditSubscriber;
use App\Listeners\IncidentAssigneeSubscriber;
use App\Listeners\NotificationSubscriber;
use App\Models\ApiToken;
use App\Services\Billing\PaymentProviderFactory;
use App\Services\Dns\SystemDnsResolver;
use App\Services\Monitoring\NativeDnsRecordResolver;
use App\Services\Monitoring\NativeDnsResolver;
use App\Services\Monitoring\NativeTcpConnector;
use App\Services\Monitoring\NativeTlsCertificateInspector;
use App\Services\SocialSignIn\SocialiteSignInGateway;
use App\Services\SocialSignIn\SocialSignInGateway;
use App\Services\Telemetry\DatabaseTelemetryIngestor;
use App\Services\Telemetry\OtlpPayloadMapper;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SocialSignInGateway::class, SocialiteSignInGateway::class);
        $this->app->bind(RequestOrigin::class, HttpRequestOrigin::class);
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->bind(MonitoringDnsResolver::class, NativeDnsResolver::class);
        $this->app->bind(DnsRecordResolver::class, NativeDnsRecordResolver::class);
        $this->app->bind(TlsCertificateInspector::class, NativeTlsCertificateInspector::class);
        $this->app->bind(TcpConnector::class, NativeTcpConnector::class);
        $this->app->bind(TelemetryIngestor::class, DatabaseTelemetryIngestor::class);
        $this->app->bind(TelemetryPayloadMapper::class, OtlpPayloadMapper::class);
        $this->app->singleton(PaymentProvider::class, fn ($app): PaymentProvider => PaymentProviderFactory::make($app['config']));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(AuditSubscriber::class);
        Event::subscribe(NotificationSubscriber::class);
        Event::subscribe(IncidentAssigneeSubscriber::class);
        View::composer('components.signal.layouts.app', ShellComposer::class);

        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        // `composer dev` also runs the Monitoring queues and the scheduler.
        DevCommands::artisan('queue:listen telemetry --queue=telemetry --tries=5 --timeout=60', 'telemetry');
        DevCommands::artisan('queue:listen checks --queue=checks --tries=1 --timeout=45', 'checks');
        DevCommands::artisan('queue:listen alerts --queue=alerts --tries=1 --timeout=45', 'alerts');
        DevCommands::artisan('schedule:work --no-interaction', 'scheduler');
        RateLimiter::for('collect', fn (Request $request): Limit => Limit::perMinute((int) config('analytics.collect_rate_per_minute', 120))->by($request->ip().'|'.(string) $request->route('publicId')));
        // Heartbeat and queue signals: a per-IP limit before authentication, then a per-monitor limit after it.
        RateLimiter::for('heartbeat-ingress', fn (Request $request): Limit => Limit::perMinute(240)->by('heartbeat-ip:'.$request->ip()));
        RateLimiter::for('heartbeats', fn (Request $request): Limit => Limit::perMinute(60)->by('heartbeat-monitor:'.(string) $request->attributes->get('heartbeat_monitor_id')));
        RateLimiter::for('queue-ingress', fn (Request $request): Limit => Limit::perMinute(2400)->by('queue-ip:'.$request->ip()));
        RateLimiter::for('queue-signals', fn (Request $request): Limit => Limit::perMinute($request->routeIs('api.queues.snapshots.store') ? 60 : 600)
            ->by('queue-monitor:'.(string) $request->attributes->get('queue_monitor_id').':'.(string) $request->route()?->getName()));
        // Three test alerts a minute per destination, so a test can't be used to flood someone's inbox or channel.
        RateLimiter::for('alert-tests', fn (Request $request): Limit => Limit::perMinute(3)->by('alert-test:'.(string) $request->route('destination')));
        // Telemetry ingest: per key (or IP before a key is known); deployments have their own, lower limit.
        RateLimiter::for('ingest', fn (Request $request): Limit => Limit::perMinute(240)->by(self::ingestKey($request)));
        RateLimiter::for('deployments', fn (Request $request): Limit => Limit::perMinute(60)->by('deployments:'.self::ingestKey($request)));
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by((string) ($request->user()?->currentAccessToken()?->getKey() ?? $request->ip())));
    }

    private static function ingestKey(Request $request): string
    {
        $token = $request->bearerToken() ?? $request->header('X-Beacon-Token');

        return is_string($token) ? hash('sha256', $token) : (string) $request->ip();
    }
}
