<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Server;
use InvalidArgumentException;
use RuntimeException;

/** The last 200 lines of a server's system logs. */
final class ServerLogs
{
    public const TYPES = [
        'apt' => 'tail -n 200 -- /var/log/apt/history.log',
        'caddy' => 'journalctl -u caddy --no-pager -n 200',
        'mysql' => 'tail -n 200 -- /var/log/mysql/error.log',
        'php' => 'journalctl -u php8.4-fpm --no-pager -n 200',
        'provisioning' => 'tail -n 200 -- /var/log/cloud-init-output.log',
    ];

    /**
     * Create a new ServerLogs instance.
     *
     * Reads server logs.
     *
     * @param  ServerShell  $shell  Runs the command for each log.
     */
    public function __construct(private readonly ServerShell $shell) {}

    /**
     * Read the tail of one of the server's logs, cut to the configured length.
     *
     * @param  Server  $server
     * @param  string  $type
     * @return string
     */
    public function read(Server $server, string $type): string
    {
        $command = self::TYPES[$type] ?? throw new InvalidArgumentException('Unsupported server log type.');
        $result = $this->shell->run($server, $command);
        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput) !== '' ? trim($result->errorOutput) : 'Unable to read the server log.');
        }

        return mb_substr($result->output, -max(1, (int) config('infrastructure.server_log_max_characters')));
    }
}
