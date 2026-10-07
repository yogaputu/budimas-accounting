<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

$erpStagingSecretPath = env(
    'ERP_POSTGRES_SECRET_FILE',
    storage_path('app/private/erp-postgres-staging.env'),
);
$erpStagingSecrets = [];

// Local development may keep the ERP staging credential outside .env. The
// file is intentionally ignored by Git and must be readable only by the
// local Accounting user. Production should inject the normal ERP_POSTGRES_*
// environment variables instead.
if (is_string($erpStagingSecretPath) && is_readable($erpStagingSecretPath)) {
    foreach (file($erpStagingSecretPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (! is_string($line) || str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);

        if ($key !== '') {
            $erpStagingSecrets[$key] = trim($value);
        }
    }
}

$erpStagingValue = static function (string $key, string $default = '') use ($erpStagingSecrets): string {
    $environmentValue = env($key);

    if (is_string($environmentValue) && trim($environmentValue) !== '') {
        return trim($environmentValue);
    }

    return (string) ($erpStagingSecrets[$key] ?? $default);
};

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
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
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        // The Accounting database owns journals, cash, receivables, and
        // reconciliation.  It is intentionally separate from the ERP DB.
        'accounting_pgsql' => [
            'driver' => 'pgsql',
            'host' => env('ACCOUNTING_POSTGRES_HOST', '127.0.0.1'),
            'port' => env('ACCOUNTING_POSTGRES_PORT', '5434'),
            'database' => env('ACCOUNTING_POSTGRES_DATABASE', 'budimas_accounting'),
            'username' => env('ACCOUNTING_POSTGRES_USER', 'accounting_app'),
            'password' => env('ACCOUNTING_POSTGRES_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('ACCOUNTING_POSTGRES_SSLMODE', 'prefer'),
        ],

        // Accounting owns this MySQL database. It stays separate from the
        // ERP and legacy SQL Server source systems, which are read-only.
        'accounting_mysql' => [
            'driver' => 'mysql',
            'url' => env('ACCOUNTING_MYSQL_URL'),
            'host' => env('ACCOUNTING_MYSQL_HOST', '127.0.0.1'),
            'port' => env('ACCOUNTING_MYSQL_PORT', '3306'),
            'database' => env('ACCOUNTING_MYSQL_DATABASE', 'budimas_accounting'),
            'username' => env('ACCOUNTING_MYSQL_USERNAME', ''),
            'password' => env('ACCOUNTING_MYSQL_PASSWORD', ''),
            'unix_socket' => env('ACCOUNTING_MYSQL_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => env('ACCOUNTING_MYSQL_STRICT', true),
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('ACCOUNTING_MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // Read-only source connection to the current ERP.  It is reserved
        // for supervised sync jobs and must never be the default connection.
        'erp_postgres' => [
            'driver' => 'pgsql',
            'host' => $erpStagingValue('ERP_POSTGRES_HOST', '127.0.0.1'),
            'port' => $erpStagingValue('ERP_POSTGRES_PORT', '15432'),
            'database' => $erpStagingValue('ERP_POSTGRES_DATABASE'),
            'username' => $erpStagingValue('ERP_POSTGRES_USER'),
            'password' => $erpStagingValue('ERP_POSTGRES_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => $erpStagingValue('ERP_POSTGRES_SCHEMA', 'public'),
            'sslmode' => $erpStagingValue('ERP_POSTGRES_SSLMODE', 'prefer'),
        ],

        // This connection is intentionally separate from the application's
        // default database. It is reserved for a dedicated SQL Server account
        // with read-only access to approved legacy objects.
        'legacy_sqlserver' => [
            'driver' => 'sqlsrv',
            'host' => env('LEGACY_SQLSERVER_HOST', '127.0.0.1'),
            'port' => env('LEGACY_SQLSERVER_PORT', '1433'),
            'database' => env('LEGACY_SQLSERVER_DATABASE', ''),
            'username' => env('LEGACY_SQLSERVER_USERNAME', ''),
            'password' => env('LEGACY_SQLSERVER_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('LEGACY_SQLSERVER_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('LEGACY_SQLSERVER_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
