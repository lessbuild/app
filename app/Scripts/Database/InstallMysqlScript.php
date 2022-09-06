<?php

namespace App\Scripts\Database;

use App\Models\Server;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class InstallMysqlScript
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Install Mysql';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Install Mysql and configure Mysql';

    /**
     * Event Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'installed-mysql';

    /**
     * Shell script to install Mysql
     *
     * @param int $step
     * @param \App\Models\Server $server
     * @return string
     */
    public function script(int $step, Server $server): string
    {
        $IP = $server->public_ip;
        $PASSWORD = Str::random();

        Session::put('mysql_password', $PASSWORD);

        return <<<SCRIPT
        provisionPing {$server->id} {$step}

        debconf-set-selections <<< "mysql-community-server mysql-community-server/data-dir select ''"
        debconf-set-selections <<< "mysql-community-server mysql-community-server/root-pass password $PASSWORD"
        debconf-set-selections <<< "mysql-community-server mysql-community-server/re-root-pass password $PASSWORD"

        # Install MySQL
        apt_wait
        yes | sudo apt install mysql-server

        # Configure Password Expiration
        echo "default_password_lifetime = 0" >> /etc/mysql/mysql.conf.d/mysqld.cnf

        # Set Character Set
        echo "" >> /etc/mysql/my.cnf
        echo "[mysqld]" >> /etc/mysql/my.cnf
        echo "default_authentication_plugin=mysql_native_password" >> /etc/mysql/my.cnf
        echo "skip-log-bin" >> /etc/mysql/my.cnf

        # Configure Max Connections
        RAM=$(awk '/^MemTotal:/{printf "%3.0f", $2 / (1024 * 1024)}' /proc/meminfo)
        MAX_CONNECTIONS=$(( 70 * \$RAM ))
        REAL_MAX_CONNECTIONS=$(( MAX_CONNECTIONS>70 ? MAX_CONNECTIONS : 100 ))
        sed -i "s/^max_connections.*=.*/max_connections=\${REAL_MAX_CONNECTIONS}/" /etc/mysql/my.cnf

        # Configure Access Permissions For Root & Oher Users
        if grep -q "bind-address" /etc/mysql/mysql.conf.d/mysqld.cnf; then
          sed -i '/^bind-address/s/bind-address.*=.*/bind-address = */' /etc/mysql/mysql.conf.d/mysqld.cnf
        else
          echo "bind-address = *" >> /etc/mysql/mysql.conf.d/mysqld.cnf
        fi

        mysql --user="root" --password="$PASSWORD" -e "CREATE USER 'root'@'$IP' IDENTIFIED BY '$PASSWORD';"
        mysql --user="root" --password="$PASSWORD" -e "CREATE USER 'root'@'%' IDENTIFIED BY '$PASSWORD';"
        mysql --user="root" --password="$PASSWORD" -e "GRANT ALL PRIVILEGES ON *.* TO root@'$IP' WITH GRANT OPTION;"
        mysql --user="root" --password="$PASSWORD" -e "GRANT ALL PRIVILEGES ON *.* TO root@'%' WITH GRANT OPTION;"
        mysql --user="root" --password="$PASSWORD" -e "FLUSH PRIVILEGES;"

        service mysql restart
        SCRIPT;
    }
}
