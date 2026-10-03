<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Contracts\Analytics\CountryLookup;
use App\Contracts\Analytics\GoogleAnalyticsData;
use App\Contracts\Analytics\SearchConsole;
use App\Contracts\DnsResolver;
use App\Contracts\Monitoring\DnsRecordResolver;
use App\Contracts\Monitoring\DnsResolver as MonitoringDnsResolver;
use App\Contracts\Monitoring\TcpConnector;
use App\Contracts\Monitoring\TlsCertificateInspector;
use App\Contracts\PaymentProvider;
use App\Contracts\RequestOrigin;
use App\Contracts\Security\DomainProbe;
use App\Contracts\Security\VulnerabilityDatabase;
use App\Contracts\SiteAudits\AuditAnalyst;
use App\Contracts\SiteAudits\AuditBrowser;
use App\Contracts\SiteAudits\WebSearch;
use App\Contracts\Telemetry\TelemetryIngestor;
use App\Contracts\Telemetry\TelemetryPayloadMapper;
use App\Http\HttpRequestOrigin;
use App\Http\View\ShellComposer;
use App\Listeners\AuditSubscriber;
use App\Listeners\BuildArtifactSubscriber;
use App\Listeners\CdnPurgeSubscriber;
use App\Listeners\DeployNotificationSubscriber;
use App\Listeners\DeployPipelineSubscriber;
use App\Listeners\EnvironmentRecipeSubscriber;
use App\Listeners\GitHubDeployStatusSubscriber;
use App\Listeners\IncidentAssigneeSubscriber;
use App\Listeners\NotificationSubscriber;
use App\Listeners\OnboardingSubscriber;
use App\Listeners\PreviewWebsiteSubscriber;
use App\Listeners\SshAccessSubscriber;
use App\Listeners\WebhookSubscriber;
use App\Models\ApiToken;
use App\Services\Admin\FeatureFlags;
use App\Services\Analytics\DbIpCountryLookup;
use App\Services\Analytics\GoogleAnalytics;
use App\Services\Analytics\GoogleSearchConsole;
use App\Services\Billing\PaymentProviderFactory;
use App\Services\Dns\SystemDnsResolver;
use App\Services\Monitoring\NativeDnsRecordResolver;
use App\Services\Monitoring\NativeDnsResolver;
use App\Services\Monitoring\NativeTcpConnector;
use App\Services\Monitoring\NativeTlsCertificateInspector;
use App\Services\Security\NetworkDomainProbe;
use App\Services\Security\OsvDatabase;
use App\Services\SiteAudits\BraveWebSearch;
use App\Services\SiteAudits\ClaudeAuditAnalyst;
use App\Services\SiteAudits\PlaywrightAuditBrowser;
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
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(SocialSignInGateway::class, SocialiteSignInGateway::class);
        $this->app->bind(RequestOrigin::class, HttpRequestOrigin::class);
        $this->app->singleton(CountryLookup::class, DbIpCountryLookup::class);
        $this->app->bind(SearchConsole::class, GoogleSearchConsole::class);
        $this->app->bind(GoogleAnalyticsData::class, GoogleAnalytics::class);
        $this->app->bind(DomainProbe::class, NetworkDomainProbe::class);
        $this->app->bind(VulnerabilityDatabase::class, OsvDatabase::class);
        $this->app->bind(AuditBrowser::class, PlaywrightAuditBrowser::class);
        $this->app->bind(AuditAnalyst::class, fn (): AuditAnalyst => new ClaudeAuditAnalyst(new AnthropicClient(apiKey: (string) config('services.anthropic.key'))));
        $this->app->bind(WebSearch::class, BraveWebSearch::class);
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->bind(MonitoringDnsResolver::class, NativeDnsResolver::class);
        $this->app->bind(DnsRecordResolver::class, NativeDnsRecordResolver::class);
        $this->app->bind(TlsCertificateInspector::class, NativeTlsCertificateInspector::class);
        $this->app->bind(TcpConnector::class, NativeTcpConnector::class);
        $this->app->bind(TelemetryIngestor::class, DatabaseTelemetryIngestor::class);
        $this->app->bind(TelemetryPayloadMapper::class, OtlpPayloadMapper::class);
        // One per request (or job), so flags are read once and a change is seen by the next request.
        $this->app->scoped(FeatureFlags::class);
        $this->app->singleton(PaymentProvider::class, fn ($app): PaymentProvider => PaymentProviderFactory::make($app['config']));
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        Event::subscribe(AuditSubscriber::class);
        Event::subscribe(NotificationSubscriber::class);
        Event::subscribe(OnboardingSubscriber::class);
        Event::subscribe(DeployNotificationSubscriber::class);
        Event::subscribe(GitHubDeployStatusSubscriber::class);
        Event::subscribe(SshAccessSubscriber::class);
        Event::subscribe(IncidentAssigneeSubscriber::class);
        Event::subscribe(PreviewWebsiteSubscriber::class);
        Event::subscribe(EnvironmentRecipeSubscriber::class);
        Event::subscribe(CdnPurgeSubscriber::class);
        Event::subscribe(BuildArtifactSubscriber::class);
        Event::subscribe(WebhookSubscriber::class);
        Event::subscribe(DeployPipelineSubscriber::class);
        View::composer('components.signal.layouts.app', ShellComposer::class);
        View::composer(['status-pages.show', 'status-pages.month', 'analytics.shared'], \App\Http\View\BrandingComposer::class);

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
        // The terminal page polls for output several times a second and posts keystrokes as they're typed.
        RateLimiter::for('terminal', fn (Request $request): Limit => Limit::perMinute(1200)->by('terminal:'.(string) $request->user()?->getAuthIdentifier().'|'.(string) $request->route()?->originalParameter('terminal')));
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by((string) ($request->user()?->currentAccessToken()?->getKey() ?? $request->ip())));
    }

    /**
     * Build the rate-limit key for telemetry ingest. Requests are grouped by a hash of their ingest token (bearer or
     * `X-Beacon-Token`), so one noisy key can't exhaust another's allowance and the raw token never appears in the
     * cache; tokenless requests fall back to the client IP.
     *
     * @param  Request  $request
     * @return string
     */
    private static function ingestKey(Request $request): string
    {
        $token = $request->bearerToken() ?? $request->header('X-Beacon-Token');

        return is_string($token) ? hash('sha256', $token) : (string) $request->ip();
    }
}
