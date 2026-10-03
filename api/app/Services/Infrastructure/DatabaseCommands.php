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
        // The slow query log, when it logs to a table: the week's slowest queries on this database, literals
        // replaced so the same query groups together.
        $normalised = "REGEXP_REPLACE(REGEXP_REPLACE(REPLACE(REPLACE(LEFT(CONVERT(sql_text USING utf8mb4), 400), '\\n', ' '), '\\t', ' '), '\\'[^\\']*\\'', '?'), '[0-9]+', '?')";
        $insight = "SELECT CONCAT('slow_log=', @@global.slow_query_log, ',', @@global.log_output);"
            ." SELECT CONCAT('slow=', COUNT(*), '\\t', ROUND(AVG(TIME_TO_SEC(query_time)), 3), '\\t', ROUND(MAX(TIME_TO_SEC(query_time)), 3), '\\t', SUM(rows_examined), '\\t', q) FROM (SELECT query_time, rows_examined, {$normalised} AS q FROM mysql.slow_log WHERE db = '{$database}' AND start_time > NOW() - INTERVAL 7 DAY) AS s GROUP BY q ORDER BY SUM(TIME_TO_SEC(query_time)) DESC LIMIT 20;"
            ." SELECT CONCAT('var=innodb_buffer_pool_size=', @@global.innodb_buffer_pool_size); SELECT CONCAT('var=max_connections=', @@global.max_connections);"
            ." SELECT CONCAT('var=innodb_data_bytes=', COALESCE(SUM(data_length + index_length), 0)) FROM information_schema.tables WHERE engine = 'InnoDB';"
            ." SHOW GLOBAL STATUS WHERE Variable_name IN ('Max_used_connections', 'Created_tmp_tables', 'Created_tmp_disk_tables', 'Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests', 'Select_full_join', 'Slow_queries', 'Uptime');";

        return $this->mysql($website, $sql).'; '.$this->mysql($website, $insight).' || true';
    }

    /**
     * Turn the server's slow query log on, logging queries over a second to the mysql.slow_log table (kept across
     * restarts where the server supports SET PERSIST).
     *
     * @param  Website  $website
     * @return string
     */
    public function enableSlowLog(Website $website): string
    {
        return $this->mysql($website, "SET PERSIST slow_query_log = 1; SET PERSIST long_query_time = 1; SET PERSIST log_output = 'TABLE';")
            .' || '.$this->mysql($website, "SET GLOBAL slow_query_log = 1; SET GLOBAL long_query_time = 1; SET GLOBAL log_output = 'TABLE';");
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
     * The rows a sample copy takes from each table.
     *
     * @var int
     */
    public const SAMPLE_ROWS = 1000;

    /**
     * Replace the target's tables with a consistent dump of the source's: all of it, the first thousand rows of each
     * table (a quick branch for previews of big databases), or the schema only. Both must be on the same server.
     *
     * @param  Website  $source
     * @param  Website  $target
     * @param  string  $mode  full, sample or schema
     * @param  bool  $anonymise  mask personal data in the copy afterwards
     * @return string
     */
    public function copy(Website $source, Website $target, string $mode = 'full', bool $anonymise = false): string
    {
        $password = $this->rootPassword($source);
        $from = $this->identifier($source->databaseIdentifier());
        $to = $this->identifier($target->databaseIdentifier());
        $rows = match ($mode) {
            'schema' => ' --no-data',
            'sample' => ' --where='.escapeshellarg('1 LIMIT '.self::SAMPLE_ROWS),
            default => '',
        };

        return 'set -o pipefail; MYSQL_PWD='.escapeshellarg($password)." mysqldump --protocol=socket -u root --single-transaction --routines --triggers --events --add-drop-table{$rows} {$from}"
            .' | MYSQL_PWD='.escapeshellarg($password)." mysql --protocol=socket -u root {$to}".($anonymise ? "\n".$this->anonymise($target) : '');
    }

    /**
     * The personal data masked in anonymised copies: column names (lower case, whole name) and the value put in their
     * place, derived from the original so distinct values stay distinct (unique indexes survive).
     *
     * @var array<string, string>
     */
    public const MASKS = [
        '^(email|e_mail|email_address|.+_email)$' => "CONCAT('user-', SUBSTRING(MD5(`%s`), 1, 12), '@example.test')",
        '^(name|first_name|last_name|full_name|firstname|lastname|surname|display_name|contact_name|billing_name|shipping_name)$' => "CONCAT('Person ', SUBSTRING(MD5(`%s`), 1, 6))",
        '^(phone|phone_number|mobile|telephone|.+_phone)$' => "CONCAT('+1555', LPAD(CONV(SUBSTRING(MD5(`%s`), 1, 6), 16, 10) % 10000000, 7, '0'))",
        '^(address|address1|address2|address_line_1|address_line_2|street|.+_address)$' => "'1 Example Street'",
        '^(ip|ip_address|last_ip|.+_ip)$' => "'0.0.0.0'",
        '^(date_of_birth|dob|birthday|birth_date)$' => 'NULL',
    ];

    /**
     * Build the commands that mask personal data in a website's database: every text column whose name matches one of
     * MASKS gets the masked value, keeping NULLs.
     *
     * @param  Website  $website
     * @return string
     */
    public function anonymise(Website $website): string
    {
        $database = $this->identifier($website->databaseIdentifier());
        $mysql = 'MYSQL_PWD='.escapeshellarg($this->rootPassword($website)).' mysql --protocol=socket -u root --batch --skip-column-names';
        $lines = [];
        foreach (self::MASKS as $pattern => $expression) {
            $find = "SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = '{$database}' AND data_type IN ('varchar','char','text','tinytext','mediumtext','longtext','date','datetime') AND LOWER(column_name) REGEXP '{$pattern}'";
            $update = str_replace('`%s`', '`$column`', $expression);
            $lines[] = "{$mysql} -e ".escapeshellarg($find).' | while IFS=$\'\t\' read -r table column; do '
                .'case "$table$column" in *[!A-Za-z0-9_]*) continue ;; esac; '
                .$mysql.' -e "'.str_replace(['"', '`'], ['\\"', '\\`'], 'UPDATE `'.$database.'`.`$table` SET `$column` = '.$update.' WHERE `$column` IS NOT NULL').'"; done';
        }

        return "# Mask personal data\n".implode("\n", $lines);
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
