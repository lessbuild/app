<?php

namespace App\Scripts\Languages;

use App\Contracts\Scripts\ServerScript;
use App\Models\Server;

class InstallPHPScript implements ServerScript
{
    /**
     * Title of the script
     */
    public static string $title = 'Install PHP';

    /**
     * Description of the script
     */
    public static string $description = 'Install PHP and configure PHP';

    /**
     * Identifier of the script
     */
    public static string $identifier = 'installed-php';

    /**
     * Shell script to run
     */
    public function script(int $step, Server $server): string
    {
        $version = '8.1';

        return <<<SCRIPT

        provisionPing {$server->id} $step

        apt_wait
        yes | sudo apt install php{$version} php{$version}-fpm php{$version}-cli php{$version}-curl \
        php{$version}-pgsql php{$version}-dev php{$version}-gd php{$version}-mbstring php{$version}-mysql php{$version}-xml php{$version}-zip \
        php{$version}-sqlite3 php{$version}-memcached php{$version}-imap php{$version}-bcmath php{$version}-soap php{$version}-curl \
        php{$version}-intl php{$version}-readline php{$version}-msgpack php{$version}-igbinary php{$version}-gmp \
        php{$version}-redis libmagickwand-dev php{$version}-imagick \

        # Misc. PHP CLI Configuration
        sudo sed -i "s/error_reporting = .*/error_reporting = E_ALL/" /etc/php/{$version}/cli/php.ini
        sudo sed -i "s/display_errors = .*/display_errors = On/" /etc/php/{$version}/cli/php.ini
        sudo sed -i "s/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/" /etc/php/{$version}/cli/php.ini
        sudo sed -i "s/memory_limit = .*/memory_limit = 512M/" /etc/php/{$version}/cli/php.ini
        sudo sed -i "s/;date.timezone.*/date.timezone = UTC/" /etc/php/{$version}/cli/php.ini

        # Misc. PHP FPM Configuration
        sudo sed -i "s/display_errors = .*/display_errors = Off/" /etc/php/{$version}/fpm/php.ini

        # Ensure PHPRedis Extension Is Available
        echo "Configuring PHPRedis"
        echo "extension=redis.so" > /etc/php/{$version}/mods-available/redis.ini

        # Ensure Imagick Is Available
        echo "Configuring Imagick"
        echo "extension=imagick.so" > /etc/php/{$version}/mods-available/imagick.ini
        SCRIPT;
    }
}
