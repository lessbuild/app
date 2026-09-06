<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Services\Runner;
use InvalidArgumentException;
use RuntimeException;

class CollectServerLogAction
{
    public const TYPES = ['apt', 'caddy', 'mysql', 'php', 'provisioning'];

    private const COMMANDS = [
        'apt' => 'tail -n 200 -- /var/log/apt/history.log',
        'caddy' => 'journalctl -u caddy --no-pager -n 200',
        'mysql' => 'tail -n 200 -- /var/log/mysql/error.log',
        'php' => 'journalctl -u php8.4-fpm --no-pager -n 200',
        'provisioning' => 'tail -n 200 -- /var/log/cloud-init-output.log',
    ];

    public function __construct(private readonly Runner $runner) {}

    public function handle(Server $server, string $type): string
    {
        $command = self::COMMANDS[$type] ?? throw new InvalidArgumentException('Unsupported server log type.');
        $process = $this->runner->server($server)->create()->execute([$command]);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Unable to retrieve server logs.');
        }

        $maximum = max(1, (int) config('lessbuild.server_log_max_characters', 262144));

        return str($process->getOutput())->substr(-$maximum)->toString();
    }
}
