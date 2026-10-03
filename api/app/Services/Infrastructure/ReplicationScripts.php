<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Server;

/**
 * Renders the scripts behind read replicas. The primary gets a replication login limited to the replica's address
 * (TLS only), its firewall opens the database port to that address alone, and it starts keeping the log replicas
 * follow. The replica then takes a full copy and follows the primary: PostgreSQL by streaming replication from a
 * pg_basebackup copy, MySQL by GTID replication from a consistent dump of the application databases plus the
 * primary's application logins. Replicas are read-only until promoted.
 */
final class ReplicationScripts
{
    /** The config file both engines get, named to load after the platform's own (99-lessbuild). */
    private const string CONFIG_NAME = 'zz-lessbuild-replication';

    /** Where a replica's background setup records its progress. */
    private const string STATE_FILE = '/root/.lessbuild-replica-setup';

    /**
     * The addresses the two servers use to reach each other: their private addresses when both sit on the same
     * provider's network in one region, otherwise their public ones (still over TLS).
     *
     * @param  Server  $primary
     * @param  Server  $replica
     * @return array{primary: string, replica: string, private: bool}
     */
    public function addresses(Server $primary, Server $replica): array
    {
        $private = $primary->private_ip !== null && $replica->private_ip !== null
            && $primary->provider_id !== null && $primary->provider_id === $replica->provider_id && $primary->region === $replica->region;

        return [
            'primary' => (string) ($private ? $primary->private_ip : $primary->public_ip),
            'replica' => (string) ($private ? $replica->private_ip : $replica->public_ip),
            'private' => $private,
        ];
    }

    /**
     * The replication login's name on the primary for one replica.
     *
     * @param  Server  $replica
     * @return string
     */
    public function login(Server $replica): string
    {
        return 'lessbuild_replica_'.$replica->id;
    }

    /**
     * Render the primary's part: log settings, the replica's login and firewall opening. For MySQL it ends by
     * printing the application logins (as `logins=` and base64) for the replica to recreate.
     *
     * @param  Server  $primary
     * @param  Server  $replica
     * @return string
     */
    public function preparePrimary(Server $primary, Server $replica): string
    {
        $addresses = $this->addresses($primary, $replica);
        $replicaIp = escapeshellarg($addresses['replica']);
        $login = $this->login($replica);
        $password = (string) $replica->replication_password;
        $comment = escapeshellarg('lessbuild replica '.$replica->id);

        if ($primary->database_engine === 'postgres') {
            $sqlPassword = escapeshellarg($password);

            return <<<SCRIPT
            set -euo pipefail
            PG_VERSION=\$(ls /etc/postgresql | sort -V | tail -n 1)
            CONF="/etc/postgresql/\$PG_VERSION/main"
            mkdir -p "\$CONF/conf.d"
            cat > "\$CONF/conf.d/{$this->configFile('conf')}" <<'PG_CONFIG'
            listen_addresses = '*'
            wal_level = replica
            max_wal_senders = 10
            wal_keep_size = '2GB'
            ssl = on
            PG_CONFIG
            sed -i '/# lessbuild replica {$replica->id}\$/d' "\$CONF/pg_hba.conf"
            echo "hostssl replication {$login} {$addresses['replica']}/32 scram-sha-256 # lessbuild replica {$replica->id}" >> "\$CONF/pg_hba.conf"
            sudo -u postgres psql -v ON_ERROR_STOP=1 -v login={$login} -v password={$sqlPassword} <<'SQL'
            SELECT format('CREATE ROLE %I', :'login') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'login') \\gexec
            SELECT format('ALTER ROLE %I WITH REPLICATION LOGIN PASSWORD %L', :'login', :'password') \\gexec
            SQL
            ufw allow from {$replicaIp} to any port 5432 proto tcp comment {$comment}
            systemctl restart postgresql
            SCRIPT;
        }

        $rootPassword = escapeshellarg((string) $primary->mysql_root_password);

        return <<<SCRIPT
        set -euo pipefail
        export MYSQL_PWD={$rootPassword}
        sed -i '/^skip-log-bin/d' /etc/mysql/mysql.conf.d/99-lessbuild.cnf
        cat > /etc/mysql/mysql.conf.d/{$this->configFile('cnf')} <<'MYSQL_CONFIG'
        [mysqld]
        bind-address = 0.0.0.0
        log_bin = /var/lib/mysql/binlog
        binlog_expire_logs_seconds = 604800
        server_id = 1
        gtid_mode = ON
        enforce_gtid_consistency = ON
        MYSQL_CONFIG
        systemctl restart mysql
        mysql --user=root <<'SQL'
        CREATE USER IF NOT EXISTS '{$login}'@'{$addresses['replica']}' IDENTIFIED WITH caching_sha2_password BY '{$password}' REQUIRE SSL;
        ALTER USER '{$login}'@'{$addresses['replica']}' IDENTIFIED WITH caching_sha2_password BY '{$password}' REQUIRE SSL;
        GRANT REPLICATION SLAVE, REPLICATION CLIENT, SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES, RELOAD, PROCESS ON *.* TO '{$login}'@'{$addresses['replica']}';
        SQL
        ufw allow from {$replicaIp} to any port 3306 proto tcp comment {$comment}
        mysql --user=root -NB -e "SELECT user, host FROM mysql.user WHERE user NOT IN ('root', 'debian-sys-maint') AND user NOT LIKE 'mysql.%' AND user NOT LIKE 'lessbuild_replica_%'" \\
            | while IFS=\$'\\t' read -r LOGIN_USER LOGIN_HOST; do
                echo "DROP USER IF EXISTS '\$LOGIN_USER'@'\$LOGIN_HOST';"
                mysql --user=root -NB -e "SET SESSION print_identified_with_as_hex = ON; SHOW CREATE USER '\$LOGIN_USER'@'\$LOGIN_HOST'" | sed 's/\$/;/'
                mysql --user=root -NB -e "SHOW GRANTS FOR '\$LOGIN_USER'@'\$LOGIN_HOST'" | sed 's/\$/;/'
            done | base64 -w0 | sed 's/^/logins=/'
        echo
        SCRIPT;
    }

    /**
     * Render the replica's part, which runs in the background since a full copy can take a while: move its current
     * data aside (kept on the server), take a full copy of the primary, and start following it read-only. Progress
     * goes to a state file that `setupState()` reads: running, done, or failed with the last lines of output.
     *
     * @param  Server  $replica
     * @param  Server  $primary
     * @param  string  $logins  For MySQL, the primary's application logins as base64 SQL (from the primary's part).
     * @return string
     */
    public function startReplica(Server $replica, Server $primary, string $logins = ''): string
    {
        $addresses = $this->addresses($primary, $replica);
        $primaryIp = $addresses['primary'];
        $login = $this->login($replica);
        $password = escapeshellarg((string) $replica->replication_password);

        if ($replica->database_engine === 'postgres') {
            $connection = escapeshellarg("host={$primaryIp} port=5432 user={$login} sslmode=require");

            return $this->tracked(<<<SCRIPT
            PG_VERSION=\$(ls /etc/postgresql | sort -V | tail -n 1)
            DATA="/var/lib/postgresql/\$PG_VERSION/main"
            systemctl stop postgresql
            if [ -d "\$DATA" ]; then mv "\$DATA" "\$DATA.before-replica-\$(date +%Y%m%d%H%M%S)"; fi
            install -d -o postgres -g postgres -m 700 "\$DATA"
            sudo -u postgres env PGPASSWORD={$password} pg_basebackup -d {$connection} -D "\$DATA" -X stream -R
            cat > "/etc/postgresql/\$PG_VERSION/main/conf.d/{$this->configFile('conf')}" <<'PG_CONFIG'
            hot_standby = on
            PG_CONFIG
            systemctl start postgresql
            SCRIPT);
        }

        $rootPassword = escapeshellarg((string) $replica->mysql_root_password);
        $serverId = 1000 + $replica->id;
        $loginsArgument = escapeshellarg($logins);
        $source = "--host={$primaryIp} --user={$login} --ssl-mode=REQUIRED";
        $sqlPassword = str_replace("'", "''", (string) $replica->replication_password);

        return $this->tracked(<<<SCRIPT
        CONFIG=/etc/mysql/mysql.conf.d/{$this->configFile('cnf')}
        sed -i '/^skip-log-bin/d' /etc/mysql/mysql.conf.d/99-lessbuild.cnf
        cat > "\$CONFIG" <<'MYSQL_CONFIG'
        [mysqld]
        bind-address = 0.0.0.0
        log_bin = /var/lib/mysql/binlog
        server_id = {$serverId}
        gtid_mode = ON
        enforce_gtid_consistency = ON
        read_only = ON
        MYSQL_CONFIG
        systemctl restart mysql
        export MYSQL_PWD={$rootPassword}
        mysql --user=root -e "STOP REPLICA; RESET REPLICA ALL;" 2>/dev/null || true
        mysql --user=root -e "RESET BINARY LOGS AND GTIDS" 2>/dev/null || mysql --user=root -e "RESET MASTER"
        DATABASES=\$(MYSQL_PWD={$password} mysql {$source} -NB -e "SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('mysql', 'sys', 'information_schema', 'performance_schema')" | tr '\\n' ' ')
        if [ -n "\$DATABASES" ]; then
            MYSQL_PWD={$password} mysqldump {$source} --single-transaction --set-gtid-purged=ON --routines --triggers --events --databases \$DATABASES | mysql --user=root
        else
            GTIDS=\$(MYSQL_PWD={$password} mysql {$source} -NB -e "SELECT @@GLOBAL.gtid_executed" | tr -d '\\n')
            if [ -n "\$GTIDS" ]; then mysql --user=root -e "SET GLOBAL gtid_purged = '\$GTIDS'"; fi
        fi
        if [ -n {$loginsArgument} ]; then echo {$loginsArgument} | base64 -d | mysql --user=root --init-command="SET SESSION sql_log_bin = 0"; fi
        mysql --user=root <<'SQL'
        CHANGE REPLICATION SOURCE TO SOURCE_HOST = '{$primaryIp}', SOURCE_PORT = 3306, SOURCE_USER = '{$login}', SOURCE_PASSWORD = '{$sqlPassword}', SOURCE_AUTO_POSITION = 1, SOURCE_SSL = 1, GET_SOURCE_PUBLIC_KEY = 1;
        START REPLICA;
        SET GLOBAL super_read_only = ON;
        SQL
        echo "super_read_only = ON" >> "\$CONFIG"
        SCRIPT);
    }

    /**
     * Render the check of a background setup's state file. It prints `running`, `done`, or `failed` followed by the
     * last lines of output, and nothing if no setup has run.
     *
     * @return string
     */
    public function setupState(): string
    {
        return 'cat '.self::STATE_FILE.' 2>/dev/null || true';
    }

    /**
     * Wrap a setup script so it records its progress in the state file.
     *
     * @param  string  $script
     * @return string
     */
    private function tracked(string $script): string
    {
        $state = self::STATE_FILE;

        return <<<SCRIPT
        set -euo pipefail
        echo running > {$state}
        trap '{ echo failed; tail -n 5 "\${0%.sh}.log"; } > {$state}' ERR
        {$script}
        echo done > {$state}
        SCRIPT;
    }

    /**
     * Render the check run on a replica. It prints `running=yes` or `running=no`, `lag=` seconds behind (empty when
     * unknown) and, when copying has stopped, `error=` with the engine's reason.
     *
     * @param  Server  $replica
     * @return string
     */
    public function status(Server $replica): string
    {
        if ($replica->database_engine === 'postgres') {
            return <<<'SCRIPT'
            ROW=$(sudo -u postgres psql -tA -F'|' -c "SELECT pg_is_in_recovery(), CASE WHEN pg_last_wal_receive_lsn() = pg_last_wal_replay_lsn() THEN 0 ELSE COALESCE(EXTRACT(EPOCH FROM now() - pg_last_xact_replay_timestamp())::int, 0) END, COALESCE((SELECT status FROM pg_stat_wal_receiver LIMIT 1), 'stopped')")
            IFS='|' read -r RECOVERY LAG RECEIVER <<< "$ROW"
            if [ "$RECOVERY" = "t" ] && [ "$RECEIVER" = "streaming" ]; then echo "running=yes"; else echo "running=no"; fi
            echo "lag=$LAG"
            if [ "$RECOVERY" != "t" ]; then echo "error=The server is no longer in recovery, so it isn't following the primary."; elif [ "$RECEIVER" != "streaming" ]; then echo "error=Not receiving the primary's log (receiver: $RECEIVER)."; fi
            SCRIPT;
        }

        $rootPassword = escapeshellarg((string) $replica->mysql_root_password);

        return <<<SCRIPT
        export MYSQL_PWD={$rootPassword}
        STATUS=\$(mysql --user=root -e "SHOW REPLICA STATUS\\G")
        field() { echo "\$STATUS" | awk -F': ' -v name="\$1" '\$1 ~ "^ *"name"\$" { sub(/^[^:]*: /, ""); print; exit }'; }
        if [ "\$(field Replica_IO_Running)" = "Yes" ] && [ "\$(field Replica_SQL_Running)" = "Yes" ]; then echo "running=yes"; else echo "running=no"; fi
        LAG=\$(field Seconds_Behind_Source)
        if [ "\$LAG" = "NULL" ]; then LAG=""; fi
        echo "lag=\$LAG"
        ERROR=\$(field Last_IO_Error)
        if [ -z "\$ERROR" ]; then ERROR=\$(field Last_SQL_Error); fi
        if [ -z "\$STATUS" ]; then ERROR="Replication isn't configured on this server."; fi
        if [ -n "\$ERROR" ]; then echo "error=\$ERROR"; fi
        SCRIPT;
    }

    /**
     * Render the promotion: the replica stops following its primary and starts accepting writes.
     *
     * @param  Server  $replica
     * @return string
     */
    public function promote(Server $replica): string
    {
        if ($replica->database_engine === 'postgres') {
            return <<<'SCRIPT'
            set -euo pipefail
            if [ "$(sudo -u postgres psql -tA -c 'SELECT pg_is_in_recovery()')" = "t" ]; then
                sudo -u postgres psql -v ON_ERROR_STOP=1 -c "SELECT pg_promote(true, 60)"
            fi
            SCRIPT;
        }

        $rootPassword = escapeshellarg((string) $replica->mysql_root_password);

        return <<<SCRIPT
        set -euo pipefail
        export MYSQL_PWD={$rootPassword}
        mysql --user=root -e "STOP REPLICA; RESET REPLICA ALL; SET GLOBAL super_read_only = OFF; SET GLOBAL read_only = OFF;"
        sed -i '/^read_only\\|^super_read_only/d' /etc/mysql/mysql.conf.d/{$this->configFile('cnf')}
        SCRIPT;
    }

    /**
     * Parse a status check's output.
     *
     * @param  string  $output
     * @return array{running: bool, lag: int|null, error: string|null}
     */
    public function parseStatus(string $output): array
    {
        $values = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (preg_match('/\A(running|lag|error)=(.*)\z/', $line, $match) === 1) {
                $values[$match[1]] = trim($match[2]);
            }
        }
        $lag = $values['lag'] ?? '';

        return [
            'running' => ($values['running'] ?? 'no') === 'yes',
            'lag' => ctype_digit($lag) ? (int) $lag : null,
            'error' => isset($values['error']) && $values['error'] !== '' ? mb_substr($values['error'], 0, 500) : null,
        ];
    }

    /**
     * The replication config file's name with the engine's extension.
     *
     * @param  string  $extension
     * @return string
     */
    private function configFile(string $extension): string
    {
        return self::CONFIG_NAME.'.'.$extension;
    }
}
