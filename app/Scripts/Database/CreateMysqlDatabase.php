<?php

namespace App\Scripts\Database;

use App\Models\Server;
use App\Models\Website;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class CreateMysqlDatabase
{
    /**
     * Title of the script
     *
     * @var string
     */
    public static string $title = 'Create Mysql Database and User';

    /**
     * Description of the script
     *
     * @var string
     */
    public static string $description = 'Create a mysql database and user for this website';

    /**
     * Event Identifier of the script
     *
     * @var string
     */
    public static string $identifier = 'created-mysql-database';

    /**
     * Shell script run
     *
     * @param int $step
     * @param \App\Models\Website $website
     * @return string
     */
    public function script(int $step, Website $website): string
    {
        $IP = $website->server->public_ip;
        $WEBSITE_NAME = $website->name;
        $PASSWORD = Session::get("{$WEBSITE_NAME}_mysql_password");
        $callback = config('app.url') . '/servers/add-website/callback/status';

        return <<<SCRIPT
        mysql --user="root" --password="$PASSWORD" -e "CREATE DATABASE $WEBSITE_NAME CHARACTER SET utf8 COLLATE utf8_unicode_ci;"
        mysql --user="root" --password="$PASSWORD" -e "CREATE USER '$WEBSITE_NAME'@'$IP' IDENTIFIED BY '$PASSWORD';"
        mysql --user="root" --password="$PASSWORD" -e "CREATE USER '$WEBSITE_NAME'@'%' IDENTIFIED BY '$PASSWORD';"
        mysql --user="root" --password="$PASSWORD" -e "GRANT ALL PRIVILEGES ON $WEBSITE_NAME.* TO '$WEBSITE_NAME'@'$IP' WITH GRANT OPTION;"
        mysql --user="root" --password="$PASSWORD" -e "GRANT ALL PRIVILEGES ON $WEBSITE_NAME.* TO '$WEBSITE_NAME'@'%' WITH GRANT OPTION;"
        mysql --user="root" --password="$PASSWORD" -e "FLUSH PRIVILEGES;"

        service mysql restart

        # Ping
        curl --insecure --user-agent "deployer" --data "status={$step}&website_id={$website->id}" $callback
        SCRIPT;
    }
}
