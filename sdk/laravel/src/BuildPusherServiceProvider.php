<?php

declare(strict_types=1);

namespace BuildPusher\Laravel;

use BuildPusher\Laravel\Commands\RecordDeployment;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wires the recorder into Laravel: requests with a timeline of their queries, mail and notifications, reported
 * exceptions, slow and N+1 queries, cache hits and misses, queue jobs, scheduled tasks, commands and logs.
 */
final class BuildPusherServiceProvider extends ServiceProvider
{
    /**
     * The log levels in order of severity.
     *
     * @var list<string>
     */
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * Long-running commands that aren't recorded as one unit of work.
     *
     * @var list<string>
     */
    private const IGNORED_COMMANDS = ['schedule:run', 'schedule:work', 'queue:work', 'queue:listen', 'horizon', 'horizon:work', 'octane:start', 'reverb:start', 'serve', 'tinker', 'buildpusher:deploy'];

    /**
     * When each running job started, by job ID.
     *
     * @var array<string, float>
     */
    private array $jobStarts = [];

    /**
     * When the running command started.
     *
     * @var float|null
     */
    private ?float $commandStart = null;

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
     * Listen for what's worth recording, and send each request's, job's or command's events once it's done.
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
                    $recorder->finishRequest(false);

                    return;
                }
            }
            $status = $event->response->getStatusCode();
            $sampled = $status >= 500 || mt_rand() / mt_getrandmax() <= (float) ($config['request_sample_rate'] ?? 1.0);
            $counters = $recorder->finishRequest($sampled);
            if (! $sampled) {
                return;
            }
            $route = $event->request->route();
            $uri = $route instanceof Route ? '/'.ltrim($route->uri(), '/') : '/'.$path;
            $started = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) $event->request->server('REQUEST_TIME_FLOAT', microtime(true));
            $recorder->record([
                'type' => 'request', 'severity' => $status >= 500 ? 'error' : 'info',
                'name' => $event->request->method().' '.$uri,
                'route' => $uri,
                'status_code' => $status,
                'duration_ms' => round(max(0.0, microtime(true) - $started) * 1000, 2),
                'attributes' => ['http.method' => $event->request->method(), ...$counters],
            ]);
        });

        Event::listen(QueryExecuted::class, fn (QueryExecuted $query) => $recorder->query($query->sql, (float) $query->time, $query->connectionName));
        Event::listen(CacheHit::class, fn () => $recorder->count('cache.hits'));
        Event::listen(CacheMissed::class, fn () => $recorder->count('cache.misses'));
        Event::listen(MessageSent::class, function (MessageSent $event) use ($recorder): void {
            $recorder->count('mail.sent');
            $recorder->detail(['type' => 'log', 'severity' => 'info', 'name' => Str::limit('Mail: '.($event->message->getSubject() ?? '(no subject)'), 255, ''), 'attributes' => ['event.kind' => 'mail']]);
        });
        Event::listen(NotificationSent::class, function (NotificationSent $event) use ($recorder): void {
            $recorder->count('notifications.sent');
            $recorder->detail(['type' => 'log', 'severity' => 'info', 'name' => Str::limit('Notification: '.class_basename($event->notification).' via '.$event->channel, 255, ''), 'attributes' => ['event.kind' => 'notification']]);
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
            $counters = $recorder->finishRequest(false);
            $recorder->record(['type' => 'job', 'severity' => $failed ? 'error' : 'info', 'name' => $event->job->resolveName(), 'duration_ms' => $started === null ? null : round((microtime(true) - $started) * 1000, 2), 'attributes' => ['queue' => $event->job->getQueue(), 'attempt' => $event->job->attempts(), 'failed' => $failed, ...$counters]]);
            $recorder->flush();
        };
        Event::listen(JobProcessed::class, fn (JobProcessed $event) => $finished($event, false));
        Event::listen(JobFailed::class, fn (JobFailed $event) => $finished($event, true));

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) use ($recorder): void {
            $recorder->record(['type' => 'job', 'severity' => ($event->task->exitCode ?? 0) === 0 ? 'info' : 'error', 'name' => Str::limit('Scheduled: '.($event->task->description ?: $event->task->getSummaryForDisplay()), 255, ''), 'duration_ms' => round($event->runtime * 1000, 2), 'attributes' => ['event.kind' => 'schedule', 'exit_code' => $event->task->exitCode]]);
            $recorder->flush();
        });
        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) use ($recorder): void {
            $recorder->record(['type' => 'job', 'severity' => 'error', 'name' => Str::limit('Scheduled: '.($event->task->description ?: $event->task->getSummaryForDisplay()), 255, ''), 'details' => Str::limit($event->exception->getMessage(), 10000, ''), 'attributes' => ['event.kind' => 'schedule', 'failed' => true]]);
            $recorder->flush();
        });
        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($recorder): void {
            if ($event->command !== '' && ! in_array($event->command, self::IGNORED_COMMANDS, true)) {
                $recorder->startTrace();
                $this->commandStart = microtime(true);
            }
        });
        Event::listen(CommandFinished::class, function (CommandFinished $event) use ($recorder): void {
            if ($event->command === '' || in_array($event->command, self::IGNORED_COMMANDS, true) || $this->commandStart === null) {
                return;
            }
            $counters = $recorder->finishRequest(false);
            $recorder->record(['type' => 'job', 'severity' => $event->exitCode === 0 ? 'info' : 'error', 'name' => 'Command: '.$event->command, 'duration_ms' => round((microtime(true) - $this->commandStart) * 1000, 2), 'attributes' => ['event.kind' => 'command', 'exit_code' => $event->exitCode, ...$counters]]);
            $this->commandStart = null;
            $recorder->flush();
        });

        $recorder->startTrace();
        $this->app->terminating(fn () => $recorder->flush());
    }
}
