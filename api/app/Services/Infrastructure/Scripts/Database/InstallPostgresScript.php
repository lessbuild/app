<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Database;

use App\Contracts\Infrastructure\ServerScript;
use App\Models\Server;
use RuntimeException;

class InstallPostgresScript implements ServerScript
{
    public const TITLE = 'Install PostgreSQL';

    public const DESCRIPTION = 'Install and configure PostgreSQL';

    public const IDENTIFIER = 'installed-postgres';

    /**
     * Render the stage that installs PostgreSQL listening on localhost only, sets the postgres user's password (the
     * server's database password) and reports progress.
     *
     * @param  int  $step
     * @param  Server  $server
     * @return string
     */
    public function script(int $step, Server $server): string
    {
        $password = $server->mysql_root_password
            ?? throw new RuntimeException('Database provisioning credentials have not been prepared.');
        $sqlPassword = escapeshellarg("ALTER USER postgres WITH PASSWORD '".str_replace("'", "''", $password)."';");

        return <<<SCRIPT
        provisionPing {$server->id} {$step}

        # Install PostgreSQL from Ubuntu's archive
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold install -y postgresql postgresql-contrib

        PG_VERSION=$(ls /etc/postgresql | sort -V | tail -n 1)
        backupManagedFile "/etc/postgresql/\$PG_VERSION/main/conf.d/99-lessbuild.conf"
        mkdir -p "/etc/postgresql/\$PG_VERSION/main/conf.d"
        cat > "/etc/postgresql/\$PG_VERSION/main/conf.d/99-lessbuild.conf" <<PG_CONFIG
        listen_addresses = 'localhost'
        PG_CONFIG

        sudo -u postgres psql -v ON_ERROR_STOP=1 -c {$sqlPassword}
        systemctl enable postgresql
        systemctl restart postgresql
        SCRIPT;
    }
}
