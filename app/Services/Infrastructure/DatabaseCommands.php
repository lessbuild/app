<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\DatabaseUser;
use App\Models\Website;
use RuntimeException;

/** Shell commands that inspect a website's MySQL database, manage extra users on it, and copy it into another website's. */
final class DatabaseCommands
{
    private const GRANTS = ['read' => 'SELECT, SHOW VIEW', 'write' => 'SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES', 'admin' => 'ALL PRIVILEGES'];

    /**
     * Print `size_bytes=`, `active_connections=` and one `table=` line per table (first 500).
     *
     * @param  Website  $website
     * @return string
     */
    public function inspect(Website $website): string
    {
        $database = $this->identifier($website->databaseIdentifier());
        $sql = "SELECT CONCAT('size_bytes=', COALESCE(SUM(data_length + index_length), 0)) FROM information_schema.tables WHERE table_schema = '{$database}';"
            ." SELECT CONCAT('active_connections=', COUNT(*)) FROM information_schema.processlist WHERE db = '{$database}';"
            ." SELECT CONCAT('table=', table_name) FROM information_schema.tables WHERE table_schema = '{$database}' ORDER BY table_name LIMIT 500;";

        return $this->mysql($website, $sql);
    }

    /**
     * Create or updates an extra database user with the grants for its privilege on the website's database only.
     *
     * @param  DatabaseUser  $user
     * @return string
     */
    public function applyUser(DatabaseUser $user): string
    {
        $website = $user->website;
        $database = $this->identifier($website->databaseIdentifier());
        $username = $this->identifier($user->username);
        $password = str_replace(['\\', "'"], ['\\\\', "''"], $user->password);
        $grant = self::GRANTS[$user->privilege] ?? throw new RuntimeException('Unknown privilege.');

        return $this->mysql($website, "CREATE USER IF NOT EXISTS '{$username}'@'localhost' IDENTIFIED BY '{$password}'; ALTER USER '{$username}'@'localhost' IDENTIFIED BY '{$password}';"
            ." REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$username}'@'localhost'; GRANT {$grant} ON `{$database}`.* TO '{$username}'@'localhost'; FLUSH PRIVILEGES;");
    }

    /**
     * Drop an extra database user.
     *
     * @param  DatabaseUser  $user
     * @return string
     */
    public function removeUser(DatabaseUser $user): string
    {
        return $this->mysql($user->website, "DROP USER IF EXISTS '{$this->identifier($user->username)}'@'localhost'; FLUSH PRIVILEGES;");
    }

    /**
     * Replace the target's tables with a consistent dump of the source's. Both must be on the same server.
     *
     * @param  Website  $source
     * @param  Website  $target
     * @return string
     */
    public function copy(Website $source, Website $target): string
    {
        $password = $this->rootPassword($source);
        $from = $this->identifier($source->databaseIdentifier());
        $to = $this->identifier($target->databaseIdentifier());

        return 'set -o pipefail; MYSQL_PWD='.escapeshellarg($password)." mysqldump --protocol=socket -u root --single-transaction --routines --triggers --events --add-drop-table {$from}"
            .' | MYSQL_PWD='.escapeshellarg($password)." mysql --protocol=socket -u root {$to}";
    }

    /**
     * Build a MySQL command over the local socket as root, with the password in the environment rather than the
     * command line.
     *
     * @param  Website  $website
     * @param  string  $sql
     * @return string
     */
    private function mysql(Website $website, string $sql): string
    {
        return 'MYSQL_PWD='.escapeshellarg($this->rootPassword($website)).' mysql --protocol=socket -u root --batch --skip-column-names -e '.escapeshellarg($sql);
    }

    /**
     * Get the server's MySQL root password; throws when none is stored.
     *
     * @param  Website  $website
     * @return string
     */
    private function rootPassword(Website $website): string
    {
        return $website->server->mysql_root_password ?? throw new RuntimeException('The server doesn’t have a stored MySQL root password.');
    }

    /**
     * Check a database or user name is a plain identifier before it goes into SQL.
     *
     * @param  string  $value
     * @return string
     */
    private function identifier(string $value): string
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,63}\z/D', $value) !== 1) {
            throw new RuntimeException('Unsafe database identifier.');
        }

        return $value;
    }
}
