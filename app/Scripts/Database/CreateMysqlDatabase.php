<?php

namespace App\Scripts\Database;

use App\Abstracts\Scripts\WebsiteProvisioningScript;
use App\Models\Website;

class CreateMysqlDatabase extends WebsiteProvisioningScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Create Mysql Database and User';

    /**
     * Description of the script
     */
    public static string $description = 'Create a mysql database and user for this website';

    /**
     * Event Identifier of the script
     */
    public static string $identifier = 'created-mysql-database';

    /**
     * Shell script run
     */
    public function script(int $step, Website $website): string
    {
        $database = $website->databaseIdentifier();
        $rootPassword = escapeshellarg((string) $website->server->mysql_root_password);
        $databasePassword = str_replace(['\\', "'"], ['\\\\', "\\'"], $website->database_password);
        $databaseUser = str_replace("'", "\\'", $database);
        $serverIp = str_replace("'", "\\'", (string) $website->server->public_ip);
        $progress = $this->progress($step, $website);
        $queries = [
            "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
            "CREATE USER IF NOT EXISTS '{$databaseUser}'@'{$serverIp}' IDENTIFIED BY '{$databasePassword}';",
            "CREATE USER IF NOT EXISTS '{$databaseUser}'@'%' IDENTIFIED BY '{$databasePassword}';",
            "GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$databaseUser}'@'{$serverIp}';",
            "GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$databaseUser}'@'%';",
            'FLUSH PRIVILEGES;',
        ];
        $commands = collect($queries)
            ->map(fn (string $query): string => 'mysql --user=root --password='.$rootPassword.' --execute='.escapeshellarg($query))
            ->implode("\n");

        return <<<SCRIPT
        {$commands}

        service mysql restart

        # Ping
        {$progress}
        SCRIPT;
    }
}
