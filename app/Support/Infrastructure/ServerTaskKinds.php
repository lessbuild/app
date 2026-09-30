<?php

declare(strict_types=1);

namespace App\Support\Infrastructure;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerFirewallRule;
use App\Models\ServerProcess;

/** The kinds of server task as they appear in addresses, with their model and the server page tab that lists them. */
final class ServerTaskKinds
{
    /**
     * Each kind's address segment, model and tab.
     *
     * @var array<string, array{0: class-string<ServerCronJob|ServerProcess|ServerFirewallRule>, 1: string}>
     */
    public const KINDS = [
        'cron-jobs' => [ServerCronJob::class, 'cron'],
        'processes' => [ServerProcess::class, 'processes'],
        'firewall-rules' => [ServerFirewallRule::class, 'firewall'],
    ];

    /**
     * Get the model class for an address segment.
     *
     * @param  string  $kind
     * @return class-string<ServerCronJob|ServerProcess|ServerFirewallRule>
     */
    public static function model(string $kind): string
    {
        return self::KINDS[$kind][0] ?? abort(404);
    }

    /**
     * Get the server page tab that lists a kind.
     *
     * @param  string  $kind
     * @return string
     */
    public static function tab(string $kind): string
    {
        return self::KINDS[$kind][1] ?? 'overview';
    }

    /**
     * Find one of a server's tasks, or answer 404.
     *
     * @param  Server  $server
     * @param  string  $kind
     * @param  int|string  $id
     * @return ServerCronJob|ServerProcess|ServerFirewallRule
     */
    public static function find(Server $server, string $kind, int|string $id): ServerCronJob|ServerProcess|ServerFirewallRule
    {
        /** @var ServerCronJob|ServerProcess|ServerFirewallRule $task */
        $task = self::model($kind)::query()->where('server_id', $server->id)->findOrFail((int) $id);

        return $task;
    }

    /**
     * Describe a task for the audit log, such as "the cron job php artisan schedule:run".
     *
     * @param  ServerCronJob|ServerProcess|ServerFirewallRule  $task
     * @return string
     */
    public static function describe(ServerCronJob|ServerProcess|ServerFirewallRule $task): string
    {
        return match (true) {
            $task instanceof ServerCronJob => __('the cron job “:command”', ['command' => str($task->command)->limit(60)->toString()]),
            $task instanceof ServerProcess => __('the process “:name”', ['name' => $task->name]),
            default => __('the firewall rule “:name” (:port/:protocol)', ['name' => $task->name, 'port' => $task->port, 'protocol' => $task->protocol]),
        };
    }
}
