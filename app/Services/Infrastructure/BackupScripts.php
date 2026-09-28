<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\BackupRestore;
use App\Models\BackupVerification;
use App\Models\WebsiteBackup;
use App\Support\Infrastructure\ResticRepository;
use RuntimeException;

/**
 * The shell scripts that back a website up with restic (database dump, .env and shared storage), restore a snapshot over
 * it with a safety rollback, and verify a snapshot in a temporary database without touching the live site.
 */
final class BackupScripts
{
    private const INSTALL_RESTIC = 'if ! command -v restic >/dev/null 2>&1; then apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq restic; fi';

    /**
     * The backup script: dumps the database, copies `.env` and shared storage into a private staging directory, backs
     * them up with restic (creating the repository the first time) tagged with the website, and keeps the last N
     * snapshots.
     */
    public function backup(WebsiteBackup $backup): string
    {
        $website = $backup->website;
        $restic = ResticRepository::environment($backup->destination, $website);
        $stage = escapeshellarg("/tmp/buildpusher-backup-{$backup->id}");
        $root = escapeshellarg("/var/www/{$website->deployment_slug}");
        $password = escapeshellarg($this->mysqlPassword($backup));
        $database = escapeshellarg($website->databaseIdentifier());
        $tag = escapeshellarg('website:'.$website->id);
        $retention = max(1, min(365, $backup->schedule->retention_count ?? 14));
        $install = self::INSTALL_RESTIC;

        return <<<BASH
        set -Eeuo pipefail
        STAGE={$stage}
        APP_ROOT={$root}
        cleanup() { rm -rf -- "\$STAGE"; }
        trap cleanup EXIT
        {$install}
        install -d -m 700 -- "\$STAGE/storage"
        MYSQL_PWD={$password} mysqldump --single-transaction --routines --triggers --events --databases {$database} > "\$STAGE/database.sql"
        cp -- "\$APP_ROOT/.env" "\$STAGE/.env"
        if [ -d "\$APP_ROOT/shared/storage" ]; then rsync -a --delete "\$APP_ROOT/shared/storage/" "\$STAGE/storage/"; fi
        cd -- "\$STAGE"
        if ! {$restic} restic snapshots --json >/dev/null 2>&1; then {$restic} restic init; fi
        {$restic} restic backup --json --tag {$tag} database.sql .env storage
        {$restic} restic forget --keep-last {$retention} --tag {$tag} --prune
        BASH;
    }

    /**
     * The restore script: restores the snapshot to a staging directory, puts the site in maintenance, saves the current
     * database, `.env` and storage, swaps in the restored ones, rebuilds caches and checks health. Any failure puts the
     * saved state back.
     */
    public function restore(BackupRestore $restore): string
    {
        $backup = $restore->backup;
        $website = $backup->website;
        $restic = ResticRepository::environment($backup->destination, $website);
        $stage = escapeshellarg("/tmp/buildpusher-restore-{$restore->id}");
        $root = escapeshellarg("/var/www/{$website->deployment_slug}");
        $current = escapeshellarg($website->deploymentPath('current'));
        $password = escapeshellarg($this->mysqlPassword($backup));
        $database = escapeshellarg($website->databaseIdentifier());
        $snapshot = escapeshellarg((string) $backup->snapshot_id);
        $health = $website->health_check_enabled
            ? 'curl --fail --silent --show-error --location --connect-timeout 5 --max-time 20 --retry 3 --output /dev/null '.escapeshellarg("https://{$website->url}{$website->health_check_path}")
            : 'true';
        $install = self::INSTALL_RESTIC;

        return <<<BASH
        set -Eeuo pipefail
        STAGE={$stage}
        APP_ROOT={$root}
        DATABASE={$database}
        SAFETY_STORAGE="\$STAGE/safety-storage"
        rollback_restore() {
            code=\$?
            trap - ERR
            if [ -f "\$STAGE/safety.sql" ]; then MYSQL_PWD={$password} mysql < "\$STAGE/safety.sql" || true; fi
            if [ -d "\$SAFETY_STORAGE" ]; then rm -rf -- "\$APP_ROOT/shared/storage"; mv -- "\$SAFETY_STORAGE" "\$APP_ROOT/shared/storage"; fi
            if [ -f "\$STAGE/safety.env" ]; then cp -- "\$STAGE/safety.env" "\$APP_ROOT/.env"; fi
            if [ -f {$current}/artisan ]; then cd -- {$current} && php artisan up || true; fi
            rm -rf -- "\$STAGE"
            exit "\$code"
        }
        trap rollback_restore ERR
        install -d -m 700 -- "\$STAGE/restore"
        {$install}
        {$restic} restic restore {$snapshot} --target "\$STAGE/restore"
        test -s "\$STAGE/restore/database.sql"
        test -d "\$STAGE/restore/storage"
        if [ -f {$current}/artisan ]; then cd -- {$current} && php artisan down --retry=30; fi
        MYSQL_PWD={$password} mysqldump --single-transaction --routines --triggers --events --databases "\$DATABASE" > "\$STAGE/safety.sql"
        cp -- "\$APP_ROOT/.env" "\$STAGE/safety.env"
        mkdir -p -- "\$APP_ROOT/shared/storage"
        mv -- "\$APP_ROOT/shared/storage" "\$SAFETY_STORAGE"
        install -d -o www-data -g www-data -m 775 -- "\$APP_ROOT/shared/storage"
        rsync -a --delete "\$STAGE/restore/storage/" "\$APP_ROOT/shared/storage/"
        cp -- "\$STAGE/restore/.env" "\$APP_ROOT/.env"
        MYSQL_PWD={$password} mysql < "\$STAGE/restore/database.sql"
        chown -R www-data:www-data "\$APP_ROOT/shared/storage"
        chmod 640 "\$APP_ROOT/.env"
        if [ -f {$current}/artisan ]; then cd -- {$current} && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan up; fi
        {$health}
        trap - ERR
        rm -rf -- "\$STAGE"
        BASH;
    }

    /** Prints BP_FAILURE_STAGE, BP_INTEGRITY_STATUS, BP_SMOKE_STATUS and BP_CLEANUP_STATUS markers as it goes. */
    public function verify(BackupVerification $verification): string
    {
        $backup = $verification->backup;
        $website = $backup->website;
        $source = $website->databaseIdentifier();
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $source) !== 1) {
            throw new RuntimeException('The website’s database name can’t be used for verification.');
        }
        $restic = ResticRepository::environment($backup->destination, $website);
        $stage = escapeshellarg("/tmp/buildpusher-restore-verification-{$verification->id}");
        $temporary = escapeshellarg("buildpusher_verify_{$verification->id}");
        $source = escapeshellarg($source);
        $snapshot = escapeshellarg($verification->snapshot_id);
        $password = escapeshellarg($this->mysqlPassword($backup));
        $current = escapeshellarg($website->deploymentPath('current'));
        $install = self::INSTALL_RESTIC;

        return <<<BASH
        set -Eeuo pipefail
        STAGE={$stage}
        TEMP_DATABASE={$temporary}
        SOURCE_DATABASE={$source}
        MYSQL_ROOT_PASSWORD={$password}
        DATABASE_CREATED=0
        cleanup_verification() {
            code=\$?
            set +e
            rm -rf -- "\$STAGE"
            files_code=\$?
            database_code=0
            if [ "\$DATABASE_CREATED" -eq 1 ]; then
                MYSQL_PWD="\$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -e "DROP DATABASE IF EXISTS \`\$TEMP_DATABASE\`" >/dev/null 2>&1
                database_code=\$?
            fi
            if [ "\$files_code" -eq 0 ] && [ "\$database_code" -eq 0 ]; then echo 'BP_CLEANUP_STATUS=passed'; else echo 'BP_CLEANUP_STATUS=failed'; if [ "\$code" -eq 0 ]; then code=1; fi; fi
            exit "\$code"
        }
        trap cleanup_verification EXIT
        echo 'BP_FAILURE_STAGE=preflight'
        test -f {$current}/artisan
        {$install}
        echo 'BP_FAILURE_STAGE=restore'
        install -d -m 700 -- "\$STAGE/restore"
        {$restic} restic restore {$snapshot} --target "\$STAGE/restore" >/dev/null
        echo 'BP_FAILURE_STAGE=integrity'
        test -s "\$STAGE/restore/database.sql"
        test -f "\$STAGE/restore/.env"
        test -d "\$STAGE/restore/storage"
        MYSQL_PWD="\$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -e "CREATE DATABASE IF NOT EXISTS \`\$TEMP_DATABASE\`"
        DATABASE_CREATED=1
        BACKTICK=\$(printf '\\140')
        awk -v source="\$SOURCE_DATABASE" -v target="\$TEMP_DATABASE" 'BEGIN { from=sprintf("%c%s%c", 96, source, 96); to=sprintf("%c%s%c", 96, target, 96) } { gsub(from, to); print }' "\$STAGE/restore/database.sql" > "\$STAGE/database.sql"
        if grep -Fq "\$BACKTICK\$SOURCE_DATABASE\$BACKTICK" "\$STAGE/database.sql"; then exit 1; fi
        MYSQL_PWD="\$MYSQL_ROOT_PASSWORD" mysql --protocol=socket --database="\$TEMP_DATABASE" < "\$STAGE/database.sql"
        TABLE_COUNT=\$(MYSQL_PWD="\$MYSQL_ROOT_PASSWORD" mysql --protocol=socket --batch --skip-column-names --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '\$TEMP_DATABASE'")
        test "\$TABLE_COUNT" -gt 0
        echo 'BP_INTEGRITY_STATUS=passed'
        echo 'BP_FAILURE_STAGE=smoke'
        cd -- {$current}
        APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE="\$TEMP_DATABASE" DB_USERNAME=root DB_PASSWORD="\$MYSQL_ROOT_PASSWORD" php artisan migrate:status --no-ansi >/dev/null
        echo 'BP_SMOKE_STATUS=passed'
        BASH;
    }

    /**
     * The server's MySQL root password; throws when none is stored.
     */
    private function mysqlPassword(WebsiteBackup $backup): string
    {
        return $backup->website->server->mysql_root_password ?? throw new RuntimeException('The server doesn’t have a stored MySQL root password.');
    }
}
