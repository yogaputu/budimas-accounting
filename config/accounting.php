<?php

return [
    'target_database' => [
        'connection' => env('ACCOUNTING_DATABASE_CONNECTION', 'accounting_mysql'),
        'label' => 'MySQL Accounting',
    ],

    // Never configure this in production. A local administrator is seeded
    // only when an explicit password is supplied in the local environment.
    'local_admin' => [
        'name' => env('ACCOUNTING_LOCAL_ADMIN_NAME', 'Local Accounting Admin'),
        'email' => env('ACCOUNTING_LOCAL_ADMIN_EMAIL', 'admin@accounting.local'),
        'password' => env('ACCOUNTING_LOCAL_ADMIN_PASSWORD'),
    ],

    'entities' => [
        'BMM' => 'PT Budimas Makmur Mulia',
        'TMP' => 'PT Tiga Mutiara Prima',
    ],

    'branch_master' => [
        'source' => 'erp_postgres',
        'sync_required' => true,
    ],

    // SQL Server and ERP are source systems only. All journal posting is
    // performed in the dedicated MySQL Accounting database after
    // reconciliation/approval.
    'sources' => [
        'erp_postgres' => [
            'label' => 'ERP PostgreSQL',
            'kind' => 'postgresql',
            'connection' => 'erp_postgres',
            'mode' => 'read_only',
        ],
        'legacy_sqlserver' => [
            'label' => 'SQL Server Legacy',
            'kind' => 'sqlserver',
            'connection' => 'legacy_sqlserver',
            'mode' => 'read_only_worker',
        ],
    ],

    'staging' => [
        'default_page_size' => 50,
        'maximum_page_size' => 100,
        'sqlserver_worker' => [
            'enabled' => env('ACCOUNTING_SQLSERVER_WORKER_ENABLED', false),
            'ssh_host' => env('ACCOUNTING_SQLSERVER_SSH_HOST', ''),
            'ssh_port' => (int) env('ACCOUNTING_SQLSERVER_SSH_PORT', 9999),
            'ssh_user' => env('ACCOUNTING_SQLSERVER_SSH_USER', ''),
            'ssh_identity_file' => env('ACCOUNTING_SQLSERVER_SSH_IDENTITY_FILE', ''),
            'remote_python' => env('ACCOUNTING_SQLSERVER_REMOTE_PYTHON', '/www/wwwroot/auditcustomer.com/.venv/bin/python'),
            'remote_php' => env('ACCOUNTING_SQLSERVER_REMOTE_PHP', '/usr/bin/php'),
            'remote_config' => env('ACCOUNTING_SQLSERVER_REMOTE_CONFIG', '/www/wwwroot/auditcustomer.com/config.php'),
        ],
        'ingest' => [
            'enabled' => env('ACCOUNTING_STAGING_INGEST_ENABLED', false),
            'token' => env('ACCOUNTING_STAGING_INGEST_TOKEN'),
        ],
    ],

    'currency' => 'IDR',
    'decimal_scale' => 2,
];
