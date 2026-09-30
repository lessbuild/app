<?php

declare(strict_types=1);

namespace BuildPusher\Laravel;

use BuildPusher\Laravel\Commands\RecordDeployment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

/** Wires the recorder into Laravel: requests, reported exceptions, slow queries, queue jobs and logs. */
final class BuildPusherServiceProvider extends ServiceProvider
{
    /**
     * The log levels in order of severity.
     *
     * @var list<string>
     */
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * When each running job started, by job ID.
     *
     * @var array<string, float>
     */
    private array $jobStarts = [];

    /**
     * Register the config and the recorder.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/buildpusher.php', 'buildpusher');
        $this->app->singleton(Recorder::class, fn ($app): Recorder => new Recorder((array) $app['config']->get('buildpusher', [])));
    }

    /**
     * Listen for what's worth recording, and send each request's or job's events once it's done.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/buildpusher.php' => config_path('buildpusher.php')], 'buildpusher-config');
        if ($this->app->runningInConsole()) {
            $this->commands([RecordDeployment::class]);
        }
        $recorder = $this->app->make(Recorder::class);
        if (! $recorder->enabled()) {
            return;
        }
        $config = (array) config('buildpusher', []);

        Event::listen(RequestHandled::class, function (RequestHandled $event) use ($recorder, $config): void {
            $path = trim($event->request->path(), '/');
            foreach ((array) ($config['ignore_paths'] ?? []) as $pattern) {
                if (Str::is(trim((string) $pattern, '/'), $path)) {
                    return;
                }
            }
            $status = $event->response->getStatusCode();
            if ($status < 500 && mt_rand() / mt_getrandmax() > (float) ($config['request_sample_rate'] ?? 1.0)) {
                return;
            }
            $route = $event->request->route();
            $started = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) $event->request->server('REQUEST_TIME_FLOAT', microtime(true));
            $recorder->record([
                'type' => 'request', 'severity' => $status >= 500 ? 'error' : 'info',
                'name' => $event->request->method().' '.(is_object($route) ? '/'.ltrim($route->uri(), '/') : '/'.$path),
                'route' => is_object($route) ? '/'.ltrim($route->uri(), '/') : '/'.$path,
                'status_code' => $status,
                'duration_ms' => round(max(0.0, microtime(true) - $started) * 1000, 2),
                'attributes' => ['http.method' => $event->request->method()],
            ]);
        });

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($recorder, $config): void {
            if ($query->time >= (int) ($config['slow_query_ms'] ?? 100)) {
                $recorder->record(['type' => 'query', 'severity' => 'warning', 'name' => Str::limit($query->sql, 255, ''), 'duration_ms' => round($query->time, 2), 'attributes' => ['db.connection' => $query->connectionName]]);
            }
        });

        $minimum = array_search((string) ($config['log_level'] ?? 'warning'), self::LEVELS, true);
        Event::listen(MessageLogged::class, function (MessageLogged $log) use ($recorder, $minimum): void {
            // Laravel logs every exception it reports, with the exception in the context: record those as exceptions.
            if (($log->context['exception'] ?? null) instanceof Throwable) {
                $recorder->exception($log->context['exception']);

                return;
            }
            $level = array_search($log->level, self::LEVELS, true);
            if ($level === false || $level < (int) $minimum) {
                return;
            }
            $recorder->record(['type' => 'log', 'severity' => match (true) {
                $level >= 5 => 'critical', $level === 4 => 'error', $level === 3 => 'warning', default => 'info'
            }, 'name' => Str::limit($log->message, 255, ''), 'details' => Str::limit($log->message, 10000, '')]);
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($recorder): void {
            $recorder->startTrace();
            $this->jobStarts[(string) $event->job->getJobId()] = microtime(true);
        });
        $finished = function (JobProcessed|JobFailed $event, bool $failed) use ($recorder): void {
            $id = (string) $event->job->getJobId();
            $started = $this->jobStarts[$id] ?? null;
            unset($this->jobStarts[$id]);
            $recorder->record(['type' => 'job', 'severity' => $failed ? 'error' : 'info', 'name' => $event->job->resolveName(), 'duration_ms' => $started === null ? null : round((microtime(true) - $started) * 1000, 2), 'attributes' => ['queue' => $event->job->getQueue(), 'attempt' => $event->job->attempts(), 'failed' => $failed]]);
            $recorder->flush();
        };
        Event::listen(JobProcessed::class, fn (JobProcessed $event) => $finished($event, false));
        Event::listen(JobFailed::class, fn (JobFailed $event) => $finished($event, true));

        $recorder->startTrace();
        $this->app->terminating(fn () => $recorder->flush());
    }
}
