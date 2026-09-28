<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Website;

use App\Models\Website;

final class CreateMysqlDatabaseScript extends WebsiteProvisioningScript
{
    /**
     * Creates the website's database and user (or resets the user's password) with every privilege on that database
     * only.
     *
     * @param  int  $step
     * @param  Website  $website
     * @return string
     */
    public function script(int $step, Website $website): string
    {
        $database = $website->databaseIdentifier();
        $rootPassword = escapeshellarg((string) $website->server?->mysql_root_password);
        $password = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $website->database_password);
        $user = str_replace("'", "\\'", $database);
        $commands = implode("\n", array_map(fn (string $query): string => 'mysql --user=root --password='.$rootPassword.' --execute='.escapeshellarg($query), [
            "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
            "CREATE USER IF NOT EXISTS '{$user}'@'localhost' IDENTIFIED BY '{$password}';",
            "ALTER USER '{$user}'@'localhost' IDENTIFIED BY '{$password}';",
            "GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$user}'@'localhost';",
            'FLUSH PRIVILEGES;',
        ]));
        $progress = $this->progress($step, $website);

        return <<<SCRIPT
        {$commands}
        service mysql restart
        {$progress}
        SCRIPT;
    }
}
