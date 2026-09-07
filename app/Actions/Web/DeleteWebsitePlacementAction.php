<?php

namespace App\Actions\Web;

use App\Models\Server;
use App\Services\Runner;
use RuntimeException;

class DeleteWebsitePlacementAction
{
    /**
     * Capture one server placement independently of the website's current server.
     *
     * @param  Server  $server  Managed server supplying its provisioning state and remote connection details.
     * @param  string  $deploymentSlug  Stable website directory and Caddy configuration identifier to remove.
     * @param  Runner|null  $runner  Optional SSH runner; null creates the default runner for this operation.
     */
    public function __construct(
        private readonly Server $server,
        private readonly string $deploymentSlug,
        private readonly ?Runner $runner = null,
    ) {}

    /**
     * Validate the deployment slug, remove its Caddy configuration and application files, drop managed MySQL resources when credentials exist, and reload Caddy.
     *
     * @throws RuntimeException If the deployment slug is unsafe or remote cleanup fails.
     */
    public function handle(): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $this->deploymentSlug)) {
            throw new RuntimeException('The website deployment identifier is invalid.');
        }

        $configPath = escapeshellarg("/etc/caddy/websites/{$this->deploymentSlug}.conf");
        $websitePath = escapeshellarg("/var/www/{$this->deploymentSlug}");
        $database = str_replace('-', '_', $this->deploymentSlug);
        $databaseUser = str_replace("'", "\\'", $database);
        $serverIp = str_replace("'", "\\'", (string) $this->server->public_ip);
        $databaseCleanup = '';
        if ($this->server->mysql_root_password) {
            $queries = escapeshellarg(implode(' ', [
                "DROP DATABASE IF EXISTS `{$database}`;",
                "DROP USER IF EXISTS '{$databaseUser}'@'{$serverIp}';",
                "DROP USER IF EXISTS '{$databaseUser}'@'%';",
                'FLUSH PRIVILEGES;',
            ]));
            $rootPassword = escapeshellarg((string) $this->server->mysql_root_password);
            $databaseCleanup = "mysql --user=root --password={$rootPassword} --execute={$queries}";
        }
        $script = <<<SCRIPT
        {$databaseCleanup}
        rm -f -- {$configPath}
        rm -rf -- {$websitePath}
        sudo systemctl reload caddy
        SCRIPT;

        $result = ($this->runner ?? new Runner)
            ->server($this->server)
            ->create()
            ->execute($script);

        if (! $result->isSuccessful()) {
            throw new RuntimeException(
                'Unable to remove the website from its server: '
                .trim($result->getErrorOutput() ?: $result->getOutput()),
            );
        }
    }
}
