<?php

namespace App\Scripts\Database;

use App\Contracts\Scripts\ServerScript;
use App\Models\Server;
use RuntimeException;

class InstallMysqlScript implements ServerScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Install Mysql';

    /**
     * Description of the script
     */
    public static string $description = 'Install Mysql and configure Mysql';

    /**
     * Event Identifier of the script
     */
    public static string $identifier = 'installed-mysql';

    /**
     * Shell script to install Mysql
     */
    public function script(int $step, Server $server): string
    {
        $password = $server->mysql_root_password
            ?? throw new RuntimeException('MySQL provisioning credentials have not been prepared.');
        $shellPassword = escapeshellarg($password);

        return <<<SCRIPT
        provisionPing {$server->id} {$step}

        MYSQL_ROOT_PASSWORD={$shellPassword}

        debconf-set-selections <<< "mysql-community-server mysql-community-server/data-dir select ''"
        debconf-set-selections <<< "mysql-community-server mysql-community-server/root-pass password \$MYSQL_ROOT_PASSWORD"
        debconf-set-selections <<< "mysql-community-server mysql-community-server/re-root-pass password \$MYSQL_ROOT_PASSWORD"

        # Install MySQL
        apt_wait
        sudo apt-get install -y mysql-server

        # Configure MySQL through an application-owned, rerunnable config file
        backupManagedFile /etc/mysql/mysql.conf.d/99-lessbuild.cnf
        RAM=$(awk '/^MemTotal:/{printf "%3.0f", $2 / (1024 * 1024)}' /proc/meminfo)
        MAX_CONNECTIONS=$(( 70 * \$RAM ))
        REAL_MAX_CONNECTIONS=$(( MAX_CONNECTIONS>70 ? MAX_CONNECTIONS : 100 ))
        cat > /etc/mysql/mysql.conf.d/99-lessbuild.cnf <<MYSQL_CONFIG
        [mysqld]
        default_password_lifetime = 0
        default_authentication_plugin=mysql_native_password
        skip-log-bin
        max_connections=\${REAL_MAX_CONNECTIONS}
        bind-address = 127.0.0.1
        mysqlx-bind-address = 127.0.0.1
        MYSQL_CONFIG

        # Accept either Ubuntu's fresh socket-auth state or the password from
        # an earlier partial run so this step is safe to resume.
        if mysql --user=root -e "SELECT 1" >/dev/null 2>&1; then
            mysql --user=root -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '\$MYSQL_ROOT_PASSWORD';"
        else
            mysql --user="root" --password="\$MYSQL_ROOT_PASSWORD" -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '\$MYSQL_ROOT_PASSWORD';"
        fi
        mysql --user="root" --password="\$MYSQL_ROOT_PASSWORD" -e "FLUSH PRIVILEGES;"

        service mysql restart
        SCRIPT;
    }
}
