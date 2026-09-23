<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

$moduleConnection = static function (string $prefix, string $database): array {
    $value = static fn (string $key, mixed $default = null): mixed => env($prefix.'_DB_'.$key, $default);
    $driver = $value('CONNECTION', env('DB_CONNECTION', 'mysql'));
    $url = $value('URL');

    return match ($driver) {
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => $url,
            'database' => $value('DATABASE', database_path('database/'.$database.'.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => (bool) $value('FOREIGN_KEYS', env('DB_FOREIGN_KEYS', true)),
            'busy_timeout' => (int) $value('BUSY_TIMEOUT', env('DB_BUSY_TIMEOUT', 5000)),
            'journal_mode' => $value('JOURNAL_MODE', env('DB_JOURNAL_MODE', 'WAL')),
            'synchronous' => $value('SYNCHRONOUS', env('DB_SYNCHRONOUS', 'NORMAL')),
        ],
        'mysql' => [
            'driver' => 'mysql',
            'url' => $url,
            'host' => $value('HOST', env('DB_HOST', '127.0.0.1')),
            'port' => $value('PORT', env('DB_PORT', '3306')),
            'database' => $value('DATABASE', $database),
            'username' => $value('USERNAME', env('DB_USERNAME', 'forge')),
            'password' => $value('PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket' => $value('SOCKET', env('DB_SOCKET', '')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => $value('SSL_CA', env('MYSQL_ATTR_SSL_CA')),
            ]) : [],
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => $url,
            'host' => $value('HOST', env('DB_HOST', '127.0.0.1')),
            'port' => $value('PORT', env('DB_PORT', '5432')),
            'database' => $value('DATABASE', $database),
            'username' => $value('USERNAME', env('DB_USERNAME', 'forge')),
            'password' => $value('PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => $value('SEARCH_PATH', 'public'),
            'sslmode' => $value('SSLMODE', 'prefer'),
        ],
        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => $url,
            'host' => $value('HOST', env('DB_HOST', 'localhost')),
            'port' => $value('PORT', env('DB_PORT', '1433')),
            'database' => $value('DATABASE', $database),
            'username' => $value('USERNAME', env('DB_USERNAME', 'forge')),
            'password' => $value('PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => $value('ENCRYPT', 'yes'),
            'trust_server_certificate' => $value('TRUST_SERVER_CERTIFICATE', 'false'),
        ],
        default => throw new InvalidArgumentException("Unsupported database driver [{$driver}] for {$prefix} module."),
    };
};

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    // Keep the legacy default until product models move to their named
    // connections. Never change this value per request host.
    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DATABASE_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => (int) env('DB_BUSY_TIMEOUT', 5000),
            'journal_mode' => env('DB_JOURNAL_MODE', 'WAL'),
            'synchronous' => env('DB_SYNCHRONOUS', 'NORMAL'),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

        'core' => $moduleConnection('CORE', 'lessbuild_core'),
        'deployer' => $moduleConnection('DEPLOYER', (string) env('DB_DATABASE', 'deployer')),
        'monitor' => $moduleConnection('MONITOR', 'lessbuild_monitor'),
        'analytics' => $moduleConnection('ANALYTICS', 'lessbuild_analytics'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as APC or Memcached. Laravel makes it easy to dig right in.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
