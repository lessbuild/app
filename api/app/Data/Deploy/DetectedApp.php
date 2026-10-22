<?php

declare(strict_types=1);

namespace App\Data\Deploy;

final readonly class DetectedApp
{
    /**
     * Create a new DetectedApp instance.
     *
     * One app found in a repository, and what deploying it takes.
     *
     * @param  string  $path  its folder in the repository ('' for the top level)
     * @param  string  $framework  e.g. laravel, nuxt, static (see StackDetector::FRAMEWORKS)
     * @param  string  $label  e.g. "Laravel 12"
     * @param  string  $runtime  the environment runtime that runs it: php, node, python, docker or compose
     * @param  string|null  $runtimeVersion  e.g. 8.4 or 22, when the app pins one
     * @param  string|null  $database  the database it uses: mysql, pgsql, sqlite, or null for none
     * @param  bool  $queue  whether it queues jobs, so needs a queue worker
     * @param  bool  $scheduler  whether it schedules tasks, so needs the scheduler
     * @param  bool  $redis  whether it uses Redis
     * @param  string|null  $buildCommand  the build commands, one per line
     * @param  string|null  $startCommand  how a Node, Python or static app starts
     * @param  int|null  $port  the port the start command listens on
     * @param  list<string>  $notes  anything worth knowing, in a sentence each
     * @param  string|null  $workerCommand  what runs its queued jobs, when it queues any
     */
    public function __construct(
        public string $path,
        public string $framework,
        public string $label,
        public string $runtime,
        public ?string $runtimeVersion = null,
        public ?string $database = null,
        public bool $queue = false,
        public bool $scheduler = false,
        public bool $redis = false,
        public ?string $buildCommand = null,
        public ?string $startCommand = null,
        public ?int $port = null,
        public array $notes = [],
        public ?string $workerCommand = null,
    ) {}

    /**
     * Whether it's an API or backend app (served by PHP or Python), which deploys before the apps that call it.
     *
     * @return bool
     */
    public function isBackend(): bool
    {
        return in_array($this->runtime, ['php', 'python'], true);
    }

    /**
     * Get it as stored and sent to pages.
     *
     * @return array{path: string, framework: string, label: string, runtime: string, runtimeVersion: string|null, database: string|null, queue: bool, scheduler: bool, redis: bool, buildCommand: string|null, startCommand: string|null, port: int|null, notes: list<string>, workerCommand: string|null}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path, 'framework' => $this->framework, 'label' => $this->label, 'runtime' => $this->runtime, 'runtimeVersion' => $this->runtimeVersion,
            'database' => $this->database, 'queue' => $this->queue, 'scheduler' => $this->scheduler, 'redis' => $this->redis,
            'buildCommand' => $this->buildCommand, 'startCommand' => $this->startCommand, 'port' => $this->port, 'notes' => $this->notes, 'workerCommand' => $this->workerCommand,
        ];
    }

    /**
     * Rebuild one from toArray()'s output.
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['path'] ?? ''), (string) ($data['framework'] ?? 'unknown'), (string) ($data['label'] ?? ''), (string) ($data['runtime'] ?? 'php'),
            isset($data['runtimeVersion']) ? (string) $data['runtimeVersion'] : null, isset($data['database']) ? (string) $data['database'] : null,
            (bool) ($data['queue'] ?? false), (bool) ($data['scheduler'] ?? false), (bool) ($data['redis'] ?? false),
            isset($data['buildCommand']) ? (string) $data['buildCommand'] : null, isset($data['startCommand']) ? (string) $data['startCommand'] : null,
            isset($data['port']) ? (int) $data['port'] : null, array_values(array_map(strval(...), (array) ($data['notes'] ?? []))),
            isset($data['workerCommand']) ? (string) $data['workerCommand'] : null,
        );
    }
}
