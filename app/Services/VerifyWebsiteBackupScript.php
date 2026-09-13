<?php

namespace App\Services;

use App\Models\BackupRestoreVerification;
use RuntimeException;

class VerifyWebsiteBackupScript
{
    /**
     * Build a temporary-target verification script without touching the live website paths.
     *
     * @param  array{repository: string, environment: string}  $restic
     * @return string A bounded shell script whose markers are safe for the job to persist.
     *
     * @throws RuntimeException If the website database identifier is not safe to interpolate into the isolated target.
     */
    public function for(BackupRestoreVerification $verification, array $restic): string
    {
        $website = $verification->backup->website;
        $database = $website->databaseIdentifier();
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $database) !== 1) {
            throw new RuntimeException('The website database identifier is unsafe for isolated verification.');
        }

        $stage = escapeshellarg('/tmp/buildpusher-restore-verification-'.$verification->id);
        $temporaryDatabase = escapeshellarg('buildpusher_verify_'.$verification->id);
        $sourceDatabase = escapeshellarg($database);
        $snapshot = escapeshellarg((string) $verification->snapshot_id);
        $rootPassword = escapeshellarg((string) $website->server->mysql_root_password);
        $currentPath = escapeshellarg($website->deploymentPath('current'));

        return <<<BASH
        set -Eeuo pipefail
        STAGE={$stage}
        TEMP_DATABASE={$temporaryDatabase}
        SOURCE_DATABASE={$sourceDatabase}
        SNAPSHOT={$snapshot}
        MYSQL_ROOT_PASSWORD={$rootPassword}
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
            if [ "\$files_code" -eq 0 ] && [ "\$database_code" -eq 0 ]; then
                echo 'BP_CLEANUP_STATUS=passed'
            else
                echo 'BP_CLEANUP_STATUS=failed'
                if [ "\$code" -eq 0 ]; then code=1; fi
            fi
            exit "\$code"
        }
        trap cleanup_verification EXIT

        echo 'BP_FAILURE_STAGE=preflight'
        test -f {$currentPath}/artisan
        if ! command -v restic >/dev/null 2>&1; then
            apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq restic
        fi

        echo 'BP_FAILURE_STAGE=restore'
        install -d -m 700 -- "\$STAGE/restore"
        {$restic['environment']} restic restore "\$SNAPSHOT" --target "\$STAGE/restore" >/dev/null

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
        cd -- {$currentPath}
        APP_ENV=testing APP_DEBUG=false DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE="\$TEMP_DATABASE" DB_USERNAME=root DB_PASSWORD="\$MYSQL_ROOT_PASSWORD" php artisan migrate:status --no-ansi >/dev/null
        echo 'BP_SMOKE_STATUS=passed'
        BASH;
    }
}
