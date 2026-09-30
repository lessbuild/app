<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\ServerCronJob;
use App\Models\ServerFirewallRule;
use App\Models\ServerProcess;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The shell commands that put a server's cron jobs, processes and firewall rules in place and take them away. Every
 * value was validated when it was saved and is escaped again here; files are written through base64 so no quoting in
 * them can reach the shell.
 */
final class ServerTaskScripts
{
    /**
     * Get the commands that create or update a task on its server.
     *
     * @param  Model  $task  a ServerCronJob, ServerProcess or ServerFirewallRule
     * @return string
     */
    public function apply(Model $task): string
    {
        return match (true) {
            $task instanceof ServerCronJob => $this->write($this->cronPath($task), $this->cronFile($task), '0644'),
            $task instanceof ServerProcess => implode("\n", [
                $this->ensureSupervisor(),
                $this->write($this->processPath($task), $this->processFile($task), '0644'),
                'supervisorctl reread >/dev/null',
                'supervisorctl update '.escapeshellarg($task->programName()),
            ]),
            $task instanceof ServerFirewallRule => 'ufw allow '.$this->firewallSpec($task).' comment '.escapeshellarg('buildpusher rule '.$task->id),
            default => throw new InvalidArgumentException('Not a server task.'),
        };
    }

    /**
     * Get the commands that take a task off its server.
     *
     * @param  Model  $task
     * @return string
     */
    public function remove(Model $task): string
    {
        return match (true) {
            $task instanceof ServerCronJob => 'rm -f '.escapeshellarg($this->cronPath($task)),
            $task instanceof ServerProcess => implode("\n", [
                'supervisorctl stop '.escapeshellarg($task->programName().':*').' >/dev/null 2>&1 || true',
                'rm -f '.escapeshellarg($this->processPath($task)),
                'supervisorctl reread >/dev/null 2>&1 || true',
                'supervisorctl update >/dev/null 2>&1 || true',
            ]),
            $task instanceof ServerFirewallRule => 'ufw delete allow '.$this->firewallSpec($task).' || true',
            default => throw new InvalidArgumentException('Not a server task.'),
        };
    }

    /**
     * Get the command that restarts every copy of a process.
     *
     * @param  ServerProcess  $process
     * @return string
     */
    public function restart(ServerProcess $process): string
    {
        return 'supervisorctl restart '.escapeshellarg($process->programName().':*');
    }

    /**
     * Get where a cron job's file lives (cron.d names may only use letters, digits, - and _).
     *
     * @param  ServerCronJob  $job
     * @return string
     */
    public function cronPath(ServerCronJob $job): string
    {
        return '/etc/cron.d/buildpusher-cron-'.$job->id;
    }

    /**
     * Build a cron job's file. Percent signs mean "new line" to cron, so they're escaped; output goes to a log in the
     * user's home folder.
     *
     * @param  ServerCronJob  $job
     * @return string
     */
    public function cronFile(ServerCronJob $job): string
    {
        $home = $job->user === 'root' ? '/root' : '/home/'.$job->user;

        return "# Managed by BuildPusher. Changes here are overwritten.\nSHELL=/bin/bash\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n"
            .$job->frequency.' '.$job->user.' '.str_replace('%', '\%', $job->command).' >> '.$home.'/.buildpusher-cron-'.$job->id.'.log 2>&1'."\n";
    }

    /**
     * Get where a process's Supervisor program lives.
     *
     * @param  ServerProcess  $process
     * @return string
     */
    public function processPath(ServerProcess $process): string
    {
        return '/etc/supervisor/conf.d/'.$process->programName().'.conf';
    }

    /**
     * Build a process's Supervisor program: as many copies as asked, restarted when they stop, given time to finish
     * their work when stopped, with output kept in a rotated log.
     *
     * @param  ServerProcess  $process
     * @return string
     */
    public function processFile(ServerProcess $process): string
    {
        $name = $process->programName();
        $lines = [
            '; Managed by BuildPusher. Changes here are overwritten.',
            "[program:{$name}]",
            'command='.$process->command,
            'user='.$process->user,
            'numprocs='.$process->processes,
            'process_name=%(program_name)s_%(process_num)02d',
            'autostart=true',
            'autorestart=true',
            'stopasgroup=true',
            'killasgroup=true',
            'stopwaitsecs='.$process->stop_wait_seconds,
            'redirect_stderr=true',
            "stdout_logfile=/var/log/supervisor/{$name}.log",
            'stdout_logfile_maxbytes=10MB',
            'stdout_logfile_backups=3',
        ];
        if ($process->directory !== null) {
            array_splice($lines, 3, 0, ['directory='.$process->directory]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Get the commands that install and start Supervisor when it isn't there yet.
     *
     * @return string
     */
    private function ensureSupervisor(): string
    {
        return 'if ! command -v supervisorctl >/dev/null 2>&1; then export DEBIAN_FRONTEND=noninteractive; apt-get update -qq && apt-get install -y -qq supervisor; fi'
            ."\nsystemctl enable --now supervisor >/dev/null 2>&1 || true";
    }

    /**
     * Describe a firewall rule the way ufw takes it: protocol, where from, and which port or range.
     *
     * @param  ServerFirewallRule  $rule
     * @return string
     */
    private function firewallSpec(ServerFirewallRule $rule): string
    {
        return 'proto '.escapeshellarg($rule->protocol).' from '.escapeshellarg($rule->source ?? 'any').' to any port '.escapeshellarg($rule->port);
    }

    /**
     * Get commands that write a file in one go, with the given permissions.
     *
     * @param  string  $path
     * @param  string  $contents
     * @param  string  $mode
     * @return string
     */
    private function write(string $path, string $contents, string $mode): string
    {
        $temporary = $path.'.buildpusher-new';

        return 'echo '.escapeshellarg(base64_encode($contents)).' | base64 -d > '.escapeshellarg($temporary)
            .' && chmod '.$mode.' '.escapeshellarg($temporary).' && mv '.escapeshellarg($temporary).' '.escapeshellarg($path);
    }
}
