<?php

namespace App\Accounting;

use App\Models\Accounting\DataSource;

final class SourceReadinessService
{
    /**
     * Return safe configuration metadata without ever opening a source
     * connection. Source credentials are intentionally excluded.
     *
     * @return array{
     *     configured: bool,
     *     connection: string|null,
     *     database: string|null,
     *     host: string|null,
     *     message: string,
     *     mode: string,
     *     network_status: string,
     *     port: int|string|null,
     *     username_configured: bool
     * }
     */
    public function inspect(DataSource $dataSource): array
    {
        if ($dataSource->key === 'legacy_sqlserver') {
            return $this->inspectSqlServerWorker($dataSource);
        }

        /** @var mixed $configuredSource */
        $configuredSource = config("accounting.sources.{$dataSource->key}");
        /** @var array{connection?: string, mode?: string} $sourceConfiguration */
        $sourceConfiguration = is_array($configuredSource) ? $configuredSource : [];
        $connectionName = $sourceConfiguration['connection'] ?? null;

        /** @var array{host?: string, port?: int|string, database?: string, username?: string}|null $connection */
        $connection = is_string($connectionName)
            ? config("database.connections.{$connectionName}")
            : null;

        $host = $this->stringOrNull($connection['host'] ?? null);
        $database = $this->stringOrNull($connection['database'] ?? null);
        $username = $this->stringOrNull($connection['username'] ?? null);
        $configured = $connection !== null && $host !== null && $database !== null && $username !== null;

        return [
            'configured' => $configured,
            'connection' => $connectionName,
            'database' => $database,
            'host' => $host,
            'message' => $configured
                ? 'Konfigurasi tersedia. Koneksi sumber belum diuji dari halaman ini.'
                : 'Konfigurasi sumber belum lengkap. Tidak ada koneksi yang dicoba.',
            'mode' => (string) ($sourceConfiguration['mode'] ?? $dataSource->access_mode),
            'network_status' => 'not_attempted',
            'port' => $connection['port'] ?? null,
            'username_configured' => $username !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function inspectSqlServerWorker(DataSource $dataSource): array
    {
        $worker = config('accounting.staging.sqlserver_worker', []);
        $configured = ($worker['enabled'] ?? false) === true;

        foreach (['ssh_host', 'ssh_user', 'ssh_identity_file', 'remote_python', 'remote_php', 'remote_config'] as $field) {
            $configured = $configured && $this->stringOrNull($worker[$field] ?? null) !== null;
        }

        $port = filter_var($worker['ssh_port'] ?? null, FILTER_VALIDATE_INT);
        $configured = $configured && $port !== false && $port >= 1 && $port <= 65535;

        return [
            'configured' => $configured,
            'connection' => 'legacy_sqlserver',
            'database' => null,
            'host' => null,
            'message' => $configured
                ? 'Penghubung SQL Server tersedia. Koneksi ke cabang diperiksa saat penarikan data.'
                : 'Konfigurasi sumber belum lengkap. Tidak ada koneksi yang dicoba.',
            'mode' => $dataSource->access_mode,
            'network_status' => 'not_attempted',
            'port' => null,
            'username_configured' => $this->stringOrNull($worker['ssh_user'] ?? null) !== null,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
