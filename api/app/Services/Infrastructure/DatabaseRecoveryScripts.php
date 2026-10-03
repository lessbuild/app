<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\BackupDestination;
use App\Models\Server;
use Carbon\CarbonImmutable;

/**
 * Renders the scripts behind point-in-time recovery for database servers, using WAL-G: a daily base backup plus the
 * PostgreSQL write-ahead log (archived as it's written) or the MySQL binary log (shipped every five minutes), kept in
 * an S3-compatible backup destination. Restoring fetches the latest base backup before the chosen moment and replays
 * the log up to it; the previous data directory is kept beside the new one.
 */
final class DatabaseRecoveryScripts
{
    /** The WAL-G release installed on database servers. */
    public const WALG_VERSION = 'v3.0.5';

    /**
     * Render the script that installs WAL-G, stores the destination's credentials for it (root-only), turns on log
     * archiving, takes the first base backup and schedules the daily one with retention.
     *
     * @param  Server  $server
     * @param  BackupDestination  $destination
     * @param  int  $retentionDays
     * @return string
     */
    public function enable(Server $server, BackupDestination $destination, int $retentionDays): string
    {
        $postgres = $server->database_engine === 'postgres';
        $asset = $postgres ? 'wal-g-pg-ubuntu-22.04-amd64' : 'wal-g-mysql-ubuntu-22.04-amd64';
        $environment = $this->environment($server, $destination);
        $retain = max(1, $retentionDays);

        $postgresSteps = <<<'SCRIPT'
            PG_VERSION=$(ls /etc/postgresql | sort -V | tail -n 1)
            cat > "/etc/postgresql/$PG_VERSION/main/conf.d/98-lessbuild-recovery.conf" <<'PG_CONFIG'
            wal_level = replica
            archive_mode = on
            archive_command = '/usr/local/bin/walg wal-push %p'
            archive_timeout = 60
            PG_CONFIG
            chown postgres:postgres /usr/local/bin/walg /etc/wal-g.env
            systemctl restart postgresql
            sudo -u postgres /usr/local/bin/walg backup-push "/var/lib/postgresql/$PG_VERSION/main"
            cat > /etc/cron.d/lessbuild-database-backup <<CRON
            15 3 * * * postgres /usr/local/bin/walg backup-push /var/lib/postgresql/$PG_VERSION/main && /usr/local/bin/walg delete retain FULL {RETAIN} --confirm
            CRON
            SCRIPT;
        $mysqlSteps = <<<'SCRIPT'
            apt_wait
            DEBIAN_FRONTEND=noninteractive apt-get install -y percona-xtrabackup-80 || DEBIAN_FRONTEND=noninteractive apt-get install -y percona-xtrabackup
            sed -i '/^skip-log-bin/d' /etc/mysql/mysql.conf.d/99-lessbuild.cnf
            cat > /etc/mysql/mysql.conf.d/98-lessbuild-recovery.cnf <<'MYSQL_CONFIG'
            [mysqld]
            log_bin = /var/lib/mysql/binlog
            binlog_expire_logs_seconds = 604800
            server_id = 1
            MYSQL_CONFIG
            systemctl restart mysql
            /usr/local/bin/walg backup-push
            cat > /etc/cron.d/lessbuild-database-backup <<CRON
            */5 * * * * root /usr/local/bin/walg binlog-push >/dev/null 2>&1
            15 3 * * * root /usr/local/bin/walg backup-push && /usr/local/bin/walg delete retain FULL {RETAIN} --confirm
            CRON
            SCRIPT;
        $engine = str_replace('{RETAIN}', (string) $retain, $postgres ? $postgresSteps : $mysqlSteps);

        return <<<SCRIPT
        set -euo pipefail
        # WAL-G {$this->version()}: continuous backup for point-in-time recovery.
        curl -fsSL -o /tmp/wal-g.tar.gz "https://github.com/wal-g/wal-g/releases/download/{$this->version()}/{$asset}.tar.gz"
        tar -xzf /tmp/wal-g.tar.gz -C /tmp
        install -m 755 /tmp/{$asset} /usr/local/bin/wal-g
        rm -f /tmp/wal-g.tar.gz /tmp/{$asset}

        umask 077
        cat > /etc/wal-g.env <<'WALG_ENV'
        {$environment}
        WALG_ENV
        chmod 600 /etc/wal-g.env
        cat > /usr/local/bin/walg <<'WALG'
        #!/bin/bash
        set -a
        . /etc/wal-g.env
        set +a
        exec /usr/local/bin/wal-g "\$@"
        WALG
        chmod 750 /usr/local/bin/walg

        {$engine}
        chmod 644 /etc/cron.d/lessbuild-database-backup
        echo "Continuous backup is on."
        SCRIPT;
    }

    /**
     * Render the script that restores the database to a moment: stop it, move the data directory aside, fetch the
     * latest base backup and replay the log up to the moment, then start it again.
     *
     * @param  Server  $server
     * @param  CarbonImmutable  $at
     * @return string
     */
    public function restore(Server $server, CarbonImmutable $at): string
    {
        $moment = $at->utc()->format('Y-m-d H:i:s');
        $iso = $at->utc()->format('Y-m-d\TH:i:s\Z');
        $stamp = $at->utc()->format('YmdHis');

        if ($server->database_engine === 'postgres') {
            return <<<SCRIPT
            set -euo pipefail
            PG_VERSION=$(ls /etc/postgresql | sort -V | tail -n 1)
            DATA="/var/lib/postgresql/\$PG_VERSION/main"
            systemctl stop postgresql
            mv "\$DATA" "\$DATA.before-restore-{$stamp}"
            sudo -u postgres /usr/local/bin/walg backup-fetch "\$DATA" LATEST
            cat > "/etc/postgresql/\$PG_VERSION/main/conf.d/97-lessbuild-restore.conf" <<'PG_CONFIG'
            restore_command = '/usr/local/bin/walg wal-fetch %f %p'
            recovery_target_time = '{$moment}+00'
            recovery_target_action = 'promote'
            PG_CONFIG
            sudo -u postgres touch "\$DATA/recovery.signal"
            systemctl start postgresql
            until sudo -u postgres psql -Atc "SELECT NOT pg_is_in_recovery()" 2>/dev/null | grep -q t; do sleep 5; done
            rm -f "/etc/postgresql/\$PG_VERSION/main/conf.d/97-lessbuild-restore.conf"
            echo "Restored to {$moment} UTC. The previous data is in \$DATA.before-restore-{$stamp}."
            SCRIPT;
        }

        return <<<SCRIPT
        set -euo pipefail
        systemctl stop mysql
        mv /var/lib/mysql /var/lib/mysql.before-restore-{$stamp}
        install -d -o mysql -g mysql -m 750 /var/lib/mysql
        /usr/local/bin/walg backup-fetch LATEST
        chown -R mysql:mysql /var/lib/mysql
        systemctl start mysql
        /usr/local/bin/walg binlog-replay --since LATEST --until "{$iso}"
        echo "Restored to {$moment} UTC. The previous data is in /var/lib/mysql.before-restore-{$stamp}."
        SCRIPT;
    }

    /**
     * Render WAL-G's environment file: where the backups go (a folder per server under the destination's prefix), the
     * destination's credentials, and for MySQL how to connect and stream backups.
     *
     * @param  Server  $server
     * @param  BackupDestination  $destination
     * @return string
     */
    private function environment(Server $server, BackupDestination $destination): string
    {
        $lines = [
            'WALG_S3_PREFIX' => 's3://'.$destination->bucket.'/'.ltrim(trim((string) $destination->path_prefix, '/').'/database-recovery/server-'.$server->id, '/'),
            'AWS_ACCESS_KEY_ID' => $destination->access_key,
            'AWS_SECRET_ACCESS_KEY' => $destination->secret_key,
            'AWS_ENDPOINT' => $destination->endpoint,
            'AWS_REGION' => $destination->region !== '' ? $destination->region : 'us-east-1',
            'AWS_S3_FORCE_PATH_STYLE' => 'true',
            'WALG_COMPRESSION_METHOD' => 'brotli',
        ];
        if ($server->database_engine === 'postgres') {
            $lines['PGHOST'] = '/var/run/postgresql';
        } else {
            $lines += [
                'WALG_MYSQL_DATASOURCE_NAME' => 'root:'.$server->mysql_root_password.'@unix(/var/run/mysqld/mysqld.sock)/mysql',
                'WALG_STREAM_CREATE_COMMAND' => 'xtrabackup --backup --stream=xbstream --datadir=/var/lib/mysql --user=root --password='.$server->mysql_root_password,
                'WALG_STREAM_RESTORE_COMMAND' => 'xbstream -x -C /var/lib/mysql',
                'WALG_MYSQL_BACKUP_PREPARE_COMMAND' => 'xtrabackup --prepare --target-dir=/var/lib/mysql',
                'WALG_MYSQL_BINLOG_REPLAY_COMMAND' => 'mysqlbinlog --stop-datetime="$WALG_MYSQL_BINLOG_END_TS" "$WALG_MYSQL_CURRENT_BINLOG" | mysql --user=root --password='.$server->mysql_root_password,
                'WALG_MYSQL_BINLOG_DST' => '/var/tmp/wal-g-binlogs',
            ];
        }

        return implode("\n", array_map(fn (string $key, string $value): string => $key.'='.$this->quote($value), array_keys($lines), $lines));
    }

    /**
     * Quote a value for a shell environment file.
     *
     * @param  string  $value
     * @return string
     */
    private function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    /**
     * Get the WAL-G release tag.
     *
     * @return string
     */
    private function version(): string
    {
        return self::WALG_VERSION;
    }
}
