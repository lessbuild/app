<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Jobs\Infrastructure\SyncServerTask;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerFirewallRule;
use App\Models\ServerProcess;
use App\Models\User;
use App\Support\Infrastructure\ServerTaskKinds;
use Closure;
use Cron\CronExpression;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveServerTask
{
    /**
     * Create a new SaveServerTask instance.
     *
     * @param  RecordAuditEntry  $audit  Records the change.
     */
    public function __construct(private readonly RecordAuditEntry $audit) {}

    /**
     * Add or change a cron job, process or firewall rule on a server, then put it in place over SSH (queued). Only
     * people who may run commands on the server can.
     *
     * @param  User  $actor
     * @param  Server  $server
     * @param  class-string<ServerCronJob|ServerProcess|ServerFirewallRule>  $type
     * @param  array<string, mixed>  $input
     * @param  ServerCronJob|ServerProcess|ServerFirewallRule|null  $task  the one to change, or null for a new one
     * @return ServerCronJob|ServerProcess|ServerFirewallRule
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Server $server, string $type, array $input, ServerCronJob|ServerProcess|ServerFirewallRule|null $task = null): ServerCronJob|ServerProcess|ServerFirewallRule
    {
        Gate::forUser($actor)->authorize('runCommands', $server);
        if ($task !== null && $task->server_id !== $server->id) {
            abort(404);
        }
        $data = Validator::make($input, $this->rules($type, $server))->validate();
        $task ??= new $type;
        $task->forceFill([
            ...$this->attributes($type, $data),
            'server_id' => $server->id,
            'status' => 'pending',
            'error' => null,
            'created_by' => $task->created_by ?? $actor->id,
        ])->save();
        $this->audit->handle(AuditAction::ServerTaskSaved, $actor, $server->account_id, ['task' => ServerTaskKinds::describe($task), 'server' => $server->label()]);
        SyncServerTask::dispatch($task::class, $task->id, 'apply')->afterCommit();

        return $task;
    }

    /**
     * Get the validation rules for a kind of task. Commands are single lines; users are the server's own user or
     * root; ports are 1–65535, alone or as a range.
     *
     * @param  string  $type
     * @param  Server  $server
     * @return array<string, mixed>
     */
    private function rules(string $type, Server $server): array
    {
        $command = ['required', 'string', 'max:1000', 'not_regex:/[\r\n\x00]/'];
        $user = ['required', Rule::in([$server->name, 'root'])];

        return match ($type) {
            ServerCronJob::class => [
                'command' => $command,
                'user' => $user,
                'frequency' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || count(preg_split('/\s+/', trim($value)) ?: []) !== 5 || ! CronExpression::isValidExpression(trim($value))) {
                        $fail(__('Use a cron schedule with five fields, such as 0 3 * * *.'));
                    }
                }],
            ],
            ServerProcess::class => [
                'name' => ['required', 'string', 'max:60'],
                'command' => $command,
                'directory' => ['nullable', 'string', 'max:255', 'regex:#^/[A-Za-z0-9._/-]*$#'],
                'user' => $user,
                'processes' => ['required', 'integer', 'min:1', 'max:20'],
                'stop_wait_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            ],
            default => [
                'name' => ['required', 'string', 'max:60'],
                'port' => ['required', 'string', 'regex:/^\d{1,5}(:\d{1,5})?$/', function (string $attribute, mixed $value, Closure $fail): void {
                    $ports = array_map('intval', explode(':', (string) $value));
                    if (min($ports) < 1 || max($ports) > 65535 || (count($ports) === 2 && $ports[0] >= $ports[1])) {
                        $fail(__('Use a port from 1 to 65535, or a range such as 8000:8010.'));
                    }
                }],
                'protocol' => ['required', Rule::in(['tcp', 'udp'])],
                'source' => ['nullable', 'string', 'max:43', function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! self::isAddressOrNetwork($value)) {
                        $fail(__('Use an IP address or a network such as 203.0.113.0/24, or leave it empty for anywhere.'));
                    }
                }],
            ],
        };
    }

    /**
     * Turn validated input into the task's columns.
     *
     * @param  string  $type
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(string $type, array $data): array
    {
        return match ($type) {
            ServerCronJob::class => ['command' => trim((string) $data['command']), 'user' => $data['user'], 'frequency' => preg_replace('/\s+/', ' ', trim((string) $data['frequency']))],
            ServerProcess::class => [
                'name' => trim((string) $data['name']), 'command' => trim((string) $data['command']), 'directory' => filled($data['directory'] ?? null) ? rtrim((string) $data['directory'], '/') ?: '/' : null,
                'user' => $data['user'], 'processes' => (int) $data['processes'], 'stop_wait_seconds' => (int) ($data['stop_wait_seconds'] ?? 10),
            ],
            default => ['name' => trim((string) $data['name']), 'port' => (string) $data['port'], 'protocol' => $data['protocol'], 'source' => filled($data['source'] ?? null) ? (string) $data['source'] : null],
        };
    }

    /**
     * Determine whether a value is an IPv4 or IPv6 address, or such an address with a network prefix.
     *
     * @param  string  $value
     * @return bool
     */
    private static function isAddressOrNetwork(string $value): bool
    {
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($prefix === null) {
            return true;
        }
        $max = str_contains((string) $address, ':') ? 128 : 32;

        return ctype_digit($prefix) && (int) $prefix <= $max;
    }
}
