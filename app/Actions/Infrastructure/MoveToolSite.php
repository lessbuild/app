<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerProcess;
use App\Models\ToolMove;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteDomain;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type Site from ToolMove
 */
final class MoveToolSite
{
    /**
     * Create a new MoveToolSite instance.
     *
     * @param  CreateWebsite  $websites  Creates the website on the chosen server.
     * @param  SaveServerTask  $tasks  Adds the site's cron jobs and daemons there.
     */
    public function __construct(private readonly CreateWebsite $websites, private readonly SaveServerTask $tasks) {}

    /**
     * Recreate a Forge or Ploi site on one of the account's servers: a website for its domain with its environment
     * file, plus the cron jobs and daemons that run in its directory, pointed at the new one. Nothing changes in the
     * other tool, and DNS keeps pointing at the old server until someone moves it.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  ToolMove  $move
     * @param  string  $key  the site's key in the move
     * @param  int  $serverId  the server to put it on
     * @return array{website: Website, tasks: int, skipped: list<string>}
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Account $account, ToolMove $move, string $key, int $serverId): array
    {
        abort_unless($move->account_id === $account->id, 404);
        $found = $move->site($key) ?? throw ValidationException::withMessages(['site' => __('That site isn’t in what was read. Read it again.')]);
        [$source, $site] = $found;
        if (isset(($move->moved ?? [])[$key]) && Website::query()->whereKey($move->moved[$key])->exists()) {
            throw ValidationException::withMessages(['site' => __(':domain has already been moved.', ['domain' => $site['domain']])]);
        }
        if (Website::query()->where('url', $site['domain'])->exists() || WebsiteDomain::query()->where('hostname', $site['domain'])->exists()) {
            throw ValidationException::withMessages(['site' => __('Another website already uses :domain.', ['domain' => $site['domain']])]);
        }
        $website = $this->websites->handle($account, $actor, ['server_id' => $serverId, 'name' => $site['domain'], 'url' => $site['domain'], 'env_file' => $site['env'], 'description' => __('Moved from :tool', ['tool' => $move->sourceName()])]);
        $server = Server::query()->findOrFail($website->server_id);
        $tasks = 0;
        $skipped = [];
        foreach ($source['crons'] as $cron) {
            if (! $this->belongsToSite($cron['command'], null, $site)) {
                continue;
            }
            $input = ['command' => $this->rewrite($cron['command'], $site, $website), 'user' => $this->user($cron['user'], $server), 'frequency' => $cron['frequency']];
            $tasks += $this->add($actor, $server, ServerCronJob::class, $input, $cron['command'], $skipped);
        }
        foreach ($source['daemons'] as $index => $daemon) {
            if (! $this->belongsToSite($daemon['command'], $daemon['directory'], $site)) {
                continue;
            }
            $input = [
                'name' => mb_substr($website->deployment_slug.'-'.($index + 1), 0, 60), 'command' => $this->rewrite($daemon['command'], $site, $website),
                'directory' => $daemon['directory'] === null ? $website->deploymentPath('current') : $this->rewrite($daemon['directory'], $site, $website),
                'user' => $this->user($daemon['user'], $server), 'processes' => min(20, $daemon['processes']),
            ];
            $tasks += $this->add($actor, $server, ServerProcess::class, $input, $daemon['command'], $skipped);
        }
        $move->forceFill(['moved' => [...($move->moved ?? []), $key => $website->id]])->save();

        return ['website' => $website, 'tasks' => $tasks, 'skipped' => $skipped];
    }

    /**
     * Determine whether a cron job or daemon belongs to the site: it runs in, or mentions, the site's directory.
     *
     * @param  string  $command
     * @param  string|null  $directory
     * @param  Site  $site
     * @return bool
     */
    private function belongsToSite(string $command, ?string $directory, array $site): bool
    {
        return str_contains($command, $site['root']) || ($directory !== null && str_starts_with($directory, $site['root']));
    }

    /**
     * Point a command or directory at the website's live release instead of the old site's directory.
     *
     * @param  string  $value
     * @param  Site  $site
     * @param  Website  $website
     * @return string
     */
    private function rewrite(string $value, array $site, Website $website): string
    {
        return str_replace([$site['root'].'/current', $site['root']], $website->deploymentPath('current'), $value);
    }

    /**
     * Map the other tool's user (forge, ploi) to the server's own user; root stays root.
     *
     * @param  string  $user
     * @param  Server  $server
     * @return string
     */
    private function user(string $user, Server $server): string
    {
        return $user === 'root' ? 'root' : $server->name;
    }

    /**
     * Add a cron job or daemon, noting it instead when it doesn't fit (an unusual schedule, say).
     *
     * @param  User  $actor
     * @param  Server  $server
     * @param  class-string<ServerCronJob|ServerProcess>  $type
     * @param  array<string, mixed>  $input
     * @param  string  $original
     * @param  list<string>  $skipped
     * @return int 1 when added, otherwise 0
     */
    private function add(User $actor, Server $server, string $type, array $input, string $original, array &$skipped): int
    {
        try {
            $this->tasks->handle($actor, $server, $type, $input);

            return 1;
        } catch (ValidationException) {
            $skipped[] = $original;

            return 0;
        }
    }
}
