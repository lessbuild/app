<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Languages;

use App\Contracts\Infrastructure\ServerScript;
use App\Models\Server;
use RuntimeException;

class InstallPHPScript implements ServerScript
{
    public const TITLE = 'Install PHP';

    public const DESCRIPTION = 'Install PHP and configure PHP';

    public const IDENTIFIER = 'installed-php';

    /**
     * Render the stage that installs PHP-FPM and its extensions and reports progress.
     *
     * @param  int  $step
     * @param  Server  $server
     * @return string
     */
    public function script(int $step, Server $server): string
    {
        $version = (string) config('infrastructure.default_php_version', '8.4');
        $install = self::install($version);

        return <<<SCRIPT

        provisionPing {$server->id} $step

        {$install}
        SCRIPT;
    }

    /**
     * Render the commands that install a PHP version's FPM, CLI and extensions and configure them, so another version
     * can be added to a server that already runs one.
     *
     * @param  string  $version  8.2 to 8.5
     * @return string
     */
    public static function install(string $version): string
    {
        if (! preg_match('/\A8\.[2-5]\z/', $version)) {
            throw new RuntimeException('The PHP version is not supported.');
        }

        return <<<SCRIPT
        apt_wait
        sudo env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold install -y php{$version} php{$version}-fpm php{$version}-cli php{$version}-curl \
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

        # php-redis and php-imagick ship and enable their own .ini files. Rewriting them drops redis's
        # "; priority=25" (it has to load after igbinary), and the next package update then enables it a second time.
        SCRIPT;
    }
}
