<?php

declare(strict_types=1);

namespace BuildPusher\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Collects events for the current request, job or command and sends them to BuildPusher in one batch once it's done.
 * Detail (every query, mail and notification) is held until the request ends, and kept only when the request is
 * sampled or failed; queries repeated five or more times are reported as N+1. Never throws: monitoring must not break
 * the app.
 */
final class Recorder
{
    /**
     * How many times the same query in one request counts as N+1.
     *
     * @var int
     */
    public const N_PLUS_ONE = 5;

    /**
     * The most detail events kept per request.
     *
     * @var int
     */
    private const MAX_DETAIL = 50;

    /**
     * The events waiting to be sent.
     *
     * @var list<array<string, mixed>>
     */
    private array $events = [];

    /**
     * The current request's detail events (queries, mail, notifications), kept only if the request is sampled.
     *
     * @var list<array<string, mixed>>
     */
    private array $detail = [];

    /**
     * The current request's counters: queries, query time, cache hits and misses, mail and notifications.
     *
     * @var array<string, int|float>
     */
    private array $counters = [];

    /**
     * How often each query ran in the current request, by its SQL.
     *
     * @var array<string, int>
     */
    private array $queries = [];

    /**
     * The trace the current request or job belongs to, so its events link up.
     *
     * @var string|null
     */
    private ?string $traceId = null;

    /**
     * Whether the recorder is sending (it's off while sending, so its own HTTP call isn't recorded).
     *
     * @var bool
     */
    private bool $sending = false;

    /**
     * Create a new Recorder instance.
     *
     * @param  array<string, mixed>  $config  The buildpusher config.
     */
    public function __construct(private readonly array $config) {}

    /**
     * Determine whether events are recorded: there's a token, and it isn't in the middle of sending.
     *
     * @return bool
     */
    public function enabled(): bool
    {
        return ! $this->sending && is_string($this->config['token'] ?? null) && $this->config['token'] !== '';
    }

    /**
     * Start a new trace, for a request, job or command, with fresh counters.
     *
     * @return void
     */
    public function startTrace(): void
    {
        $this->traceId = bin2hex(random_bytes(16));
        $this->detail = [];
        $this->counters = [];
        $this->queries = [];
    }

    /**
     * Add an event, with the service, release and trace filled in.
     *
     * @param  array<string, mixed>  $event
     * @return void
     */
    public function record(array $event): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->events[] = $this->stamp($event);
        if (count($this->events) >= 100) {
            $this->flush();
        }
    }

    /**
     * Add a detail event (a query, mail or notification) to the current request's timeline, up to fifty.
     *
     * @param  array<string, mixed>  $event
     * @return void
     */
    public function detail(array $event): void
    {
        if ($this->enabled() && count($this->detail) < self::MAX_DETAIL) {
            $this->detail[] = $this->stamp($event);
        }
    }

    /**
     * Count something that happened in the current request, such as a cache hit.
     *
     * @param  string  $counter
     * @param  int|float  $by
     * @return void
     */
    public function count(string $counter, int|float $by = 1): void
    {
        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + $by;
    }

    /**
     * Note a query: count it and its time, remember its SQL for N+1 detection, and add it to the timeline.
     *
     * @param  string  $sql
     * @param  float  $milliseconds
     * @param  string|null  $connection
     * @return void
     */
    public function query(string $sql, float $milliseconds, ?string $connection): void
    {
        $this->count('db.query_count');
        $this->count('db.time_ms', $milliseconds);
        $this->queries[$sql] = ($this->queries[$sql] ?? 0) + 1;
        $slow = $milliseconds >= (int) ($this->config['slow_query_ms'] ?? 100);
        $event = ['type' => 'query', 'severity' => $slow ? 'warning' : 'info', 'name' => Str::limit($sql, 255, ''), 'duration_ms' => round($milliseconds, 2), 'attributes' => ['db.connection' => $connection, 'db.slow' => $slow]];
        // Slow queries are always kept; the rest only as part of a sampled request's timeline.
        $slow ? $this->record($event) : $this->detail($event);
    }

    /**
     * Finish the current request: keep its timeline when sampled or failed, report N+1 queries, and return its
     * counters for the request event.
     *
     * @param  bool  $keepDetail
     * @return array<string, int|float>
     */
    public function finishRequest(bool $keepDetail): array
    {
        if ($keepDetail) {
            array_push($this->events, ...$this->detail);
        }
        foreach ($this->queries as $sql => $times) {
            if ($times >= self::N_PLUS_ONE) {
                $this->record(['type' => 'query', 'severity' => 'warning', 'name' => Str::limit('N+1: '.$sql, 255, ''), 'details' => "The same query ran {$times} times in one request. Eager load the relation (with()) or batch the lookups.\n\n{$sql}", 'attributes' => ['db.n_plus_one' => $times]]);
            }
        }
        $counters = array_map(fn (int|float $value): int|float => is_float($value) ? round($value, 2) : $value, $this->counters);
        $this->detail = [];
        $this->queries = [];
        $this->counters = [];

        return $counters;
    }

    /**
     * Record an exception, with its class, message, file, line, trace and the (hashed) signed-in user.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function exception(Throwable $exception): void
    {
        $this->record([
            'type' => 'exception', 'severity' => 'error',
            'name' => $exception::class,
            'title' => Str::limit($exception->getMessage() !== '' ? $exception->getMessage() : $exception::class, 255, ''),
            'details' => Str::limit($exception::class.': '.$exception->getMessage().' at '.$exception->getFile().':'.$exception->getLine()."\n".$exception->getTraceAsString(), 10000, ''),
            'route' => $this->route(),
        ]);
    }

    /**
     * Get the events waiting to be sent.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->events;
    }

    /**
     * Send the waiting events in one batch, and forget them whether or not it worked.
     *
     * @return void
     */
    public function flush(): void
    {
        if ($this->events === [] || ! $this->enabled()) {
            $this->events = [];

            return;
        }
        $events = $this->events;
        $this->events = [];
        $this->sending = true;
        try {
            Http::timeout(3)->connectTimeout(2)->withToken((string) $this->config['token'])->acceptJson()
                ->post(rtrim((string) $this->config['endpoint'], '/').'/ingest', ['batch_id' => (string) Str::uuid(), 'events' => $events]);
        } catch (Throwable) {
            // Dropped: monitoring must never break the app.
        } finally {
            $this->sending = false;
        }
    }

    /**
     * Fill in an event's ID, time, service, trace, release and signed-in user (hashed with the app key, so
     * BuildPusher can count affected people without knowing who they are).
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function stamp(array $event): array
    {
        $attributes = array_filter(['service.version' => $this->config['release'] ?? null, 'user.id' => $this->userHash(), ...($event['attributes'] ?? [])], fn (mixed $value): bool => $value !== null);

        return array_filter([
            'id' => (string) Str::uuid(),
            'timestamp' => now('UTC')->toIso8601ZuluString('millisecond'),
            'service' => (string) ($this->config['service'] ?? 'laravel'),
            'trace_id' => $this->traceId,
            'span_id' => bin2hex(random_bytes(8)),
            ...$event,
            'attributes' => $attributes === [] ? null : $attributes,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Hash the signed-in user's ID with the app key, or null when nobody is signed in.
     *
     * @return string|null
     */
    private function userHash(): ?string
    {
        try {
            $id = Auth::hasResolvedGuards() ? Auth::id() : null;
        } catch (Throwable) {
            return null;
        }

        return $id === null ? null : substr(hash_hmac('sha256', (string) $id, (string) config('app.key')), 0, 32);
    }

    /**
     * Get the current request's route, when there is one.
     *
     * @return string|null
     */
    private function route(): ?string
    {
        try {
            $route = app()->bound('request') ? request()->route() : null;
        } catch (Throwable) {
            return null;
        }

        return $route instanceof Route ? '/'.ltrim($route->uri(), '/') : null;
    }
}
