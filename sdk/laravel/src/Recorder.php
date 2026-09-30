<?php

declare(strict_types=1);

namespace BuildPusher\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Collects events for the current request or job and sends them to BuildPusher in one batch, after the response has
 * gone out. Never throws: monitoring must not break the app.
 */
final class Recorder
{
    /**
     * The events waiting to be sent.
     *
     * @var list<array<string, mixed>>
     */
    private array $events = [];

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
     * Start a new trace, for a request or a job.
     *
     * @return void
     */
    public function startTrace(): void
    {
        $this->traceId = bin2hex(random_bytes(16));
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
        $attributes = array_filter(['service.version' => $this->config['release'] ?? null, ...($event['attributes'] ?? [])], fn (mixed $value): bool => $value !== null);
        $this->events[] = array_filter([
            'id' => (string) Str::uuid(),
            'timestamp' => now('UTC')->toIso8601ZuluString('millisecond'),
            'service' => (string) ($this->config['service'] ?? 'laravel'),
            'trace_id' => $this->traceId,
            'span_id' => bin2hex(random_bytes(8)),
            ...$event,
            'attributes' => $attributes === [] ? null : $attributes,
        ], fn (mixed $value): bool => $value !== null);
        if (count($this->events) >= 100) {
            $this->flush();
        }
    }

    /**
     * Record an exception, with its class, message, file, line and trace.
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
