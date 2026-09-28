<?php

declare(strict_types=1);

namespace App\Jobs\Infrastructure;

use App\Models\Server;
use App\Models\Website;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Removes a website's files, Caddy site, and MySQL database and user from one server (its previous one after a move, or both on delete). */
final class RemoveWebsitePlacement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Removing files can fail while the server is busy, so it gets three tries.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Seconds between tries.
     *
     * @var int
     */
    public int $backoff = 10;

    /**
     * Cleans a website off a server it no longer runs on (after a move or deletion): its Caddy site, files, database and
     * database users.
     *
     * @param  int  $websiteId  The website, which may be deleted by now.
     * @param  int  $serverId  The server it was on.
     * @param  string  $slug  The website's directory and database name on that server, checked again before use because it goes into shell commands.
     */
    public function __construct(public readonly int $websiteId, public readonly int $serverId, public readonly string $slug) {}

    /**
     * Removes everything the website had on the server and clears the pending cleanup. A server that no longer exists
     * has nothing to clean.
     *
     * @param  ServerShell  $shell
     * @return void
     */
    public function handle(ServerShell $shell): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/', $this->slug) !== 1) {
            throw new RuntimeException('The website directory name is invalid.');
        }
        $server = Server::query()->find($this->serverId);
        if ($server !== null) {
            $database = str_replace('-', '_', $this->slug);
            $cleanup = '';
            if ($server->mysql_root_password !== null) {
                $queries = escapeshellarg("DROP DATABASE IF EXISTS `{$database}`; DROP USER IF EXISTS '{$database}'@'localhost'; DROP USER IF EXISTS '{$database}'@'%'; FLUSH PRIVILEGES;");
                $cleanup = 'mysql --user=root --password='.escapeshellarg($server->mysql_root_password).' --execute='.$queries."\n";
            }
            $result = $shell->run($server, $cleanup.'rm -f -- '.escapeshellarg("/etc/caddy/websites/{$this->slug}.conf")."\nrm -rf -- ".escapeshellarg("/var/www/{$this->slug}")."\nsudo systemctl reload caddy");
            if (! $result->successful()) {
                throw new RuntimeException('Couldn’t remove the website from '.$server->label().': '.$result->combined());
            }
        }
        Website::withTrashed()->whereKey($this->websiteId)->where('previous_server_id', $this->serverId)->update(['previous_server_id' => null, 'placement_cleanup_error' => null]);
    }

    /**
     * Records why cleanup failed, so the website page can show it.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Website::withTrashed()->whereKey($this->websiteId)->update(['placement_cleanup_error' => Str::limit($exception->getMessage(), 2000)]);
    }
}
