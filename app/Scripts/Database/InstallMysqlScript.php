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
        $ip = escapeshellarg((string) $server->public_ip);
        $password = $server->mysql_root_password
            ?? throw new RuntimeException('MySQL provisioning credentials have not been prepared.');
        $shellPassword = escapeshellarg($password);

        return <<<SCRIPT
        provisionPing {$server->id} {$step}

        SERVER_IP={$ip}
        MYSQL_ROOT_PASSWORD={$shellPassword}

        debconf-set-selections <<< "mysql-community-server mysql-community-server/data-dir select ''"
        debconf-set-selections <<< "mysql-community-server mysql-community-server/root-pass password \$MYSQL_ROOT_PASSWORD"
        debconf-set-selections <<< "mysql-community-server mysql-community-server/re-root-pass password \$MYSQL_ROOT_PASSWORD"

        # Install MySQL
        apt_wait
        yes | sudo apt install mysql-server

        # Configure MySQL through an application-owned, rerunnable config file
        RAM=$(awk '/^MemTotal:/{printf "%3.0f", $2 / (1024 * 1024)}' /proc/meminfo)
        MAX_CONNECTIONS=$(( 70 * \$RAM ))
        REAL_MAX_CONNECTIONS=$(( MAX_CONNECTIONS>70 ? MAX_CONNECTIONS : 100 ))
        cat > /etc/mysql/mysql.conf.d/99-lessbuild.cnf <<MYSQL_CONFIG
        [mysqld]
        default_password_lifetime = 0
        default_authentication_plugin=mysql_native_password
        skip-log-bin
        max_connections=\${REAL_MAX_CONNECTIONS}
        bind-address = *
        MYSQL_CONFIG

        # Configure access permissions without failing when this step is resumed
        mysql --user="root" --password="\$MYSQL_ROOT_PASSWORD" -e "CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED BY '\$MYSQL_ROOT_PASSWORD'; ALTER USER 'root'@'%' IDENTIFIED BY '\$MYSQL_ROOT_PASSWORD'; GRANT ALL PRIVILEGES ON *.* TO root@'%' WITH GRANT OPTION;"
        if [ -n "\$SERVER_IP" ]; then
            mysql --user="root" --password="\$MYSQL_ROOT_PASSWORD" -e "CREATE USER IF NOT EXISTS 'root'@'\$SERVER_IP' IDENTIFIED BY '\$MYSQL_ROOT_PASSWORD'; ALTER USER 'root'@'\$SERVER_IP' IDENTIFIED BY '\$MYSQL_ROOT_PASSWORD'; GRANT ALL PRIVILEGES ON *.* TO root@'\$SERVER_IP' WITH GRANT OPTION;"
        fi
        mysql --user="root" --password="\$MYSQL_ROOT_PASSWORD" -e "FLUSH PRIVILEGES;"

        service mysql restart
        SCRIPT;
    }
}
