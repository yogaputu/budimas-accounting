<?php

namespace Tests\Feature;

use App\Accounting\SourceReadinessService;
use App\Models\Accounting\DataSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SourceReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_source_readiness_without_opening_a_connection(): void
    {
        config()->set('accounting.sources.erp_postgres', [
            'connection' => 'erp_postgres',
            'mode' => 'read_only',
        ]);
        config()->set('database.connections.erp_postgres', [
            'host' => 'erp.private.example',
            'port' => 5432,
            'database' => 'erp',
            'username' => 'accounting_reader',
            'password' => 'never-exposed',
        ]);
        $dataSource = DataSource::factory()->create([
            'key' => 'erp_postgres',
            'access_mode' => 'read_only',
        ]);

        $readiness = app(SourceReadinessService::class)->inspect($dataSource);

        $this->assertTrue($readiness['configured']);
        $this->assertSame('not_attempted', $readiness['network_status']);
        $this->assertSame('erp.private.example', $readiness['host']);
        $this->assertSame('erp', $readiness['database']);
        $this->assertTrue($readiness['username_configured']);
        $this->assertStringNotContainsString('never-exposed', json_encode($readiness, JSON_THROW_ON_ERROR));
    }

    public function test_it_identifies_an_incomplete_source_without_trying_to_connect(): void
    {
        config()->set('accounting.staging.sqlserver_worker.enabled', false);
        config()->set('accounting.sources.legacy_sqlserver', [
            'connection' => 'legacy_sqlserver',
            'mode' => 'read_only_worker',
        ]);
        config()->set('database.connections.legacy_sqlserver', [
            'host' => '127.0.0.1',
            'port' => 1433,
            'database' => '',
            'username' => '',
        ]);
        $dataSource = DataSource::factory()->create([
            'key' => 'legacy_sqlserver',
            'access_mode' => 'read_only_worker',
        ]);

        $readiness = app(SourceReadinessService::class)->inspect($dataSource);

        $this->assertFalse($readiness['configured']);
        $this->assertSame('not_attempted', $readiness['network_status']);
        $this->assertSame('Konfigurasi sumber belum lengkap. Tidak ada koneksi yang dicoba.', $readiness['message']);
    }

    public function test_configured_worker_readiness_does_not_run_a_process_or_expose_connection_details(): void
    {
        Process::preventStrayProcesses();
        Process::fake([]);
        config()->set('accounting.staging.sqlserver_worker', $this->workerConfiguration());
        config()->set('database.connections.legacy_sqlserver', [
            'host' => '', 'database' => '', 'username' => '',
        ]);
        $dataSource = DataSource::factory()->create([
            'key' => 'legacy_sqlserver',
            'access_mode' => 'read_only_worker',
        ]);

        $readiness = app(SourceReadinessService::class)->inspect($dataSource);

        $this->assertTrue($readiness['configured']);
        $this->assertSame('legacy_sqlserver', $readiness['connection']);
        $this->assertSame('read_only_worker', $readiness['mode']);
        $this->assertSame('not_attempted', $readiness['network_status']);
        $this->assertSame('Penghubung SQL Server tersedia. Koneksi ke cabang diperiksa saat penarikan data.', $readiness['message']);
        $this->assertTrue($readiness['username_configured']);
        $this->assertNull($readiness['host']);
        $this->assertNull($readiness['database']);
        $this->assertNull($readiness['port']);
        $serialized = json_encode($readiness, JSON_THROW_ON_ERROR);
        foreach (['worker.private.example', 'private-worker-user', '/private/worker-key', '/private/sources.php'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $serialized);
        }
        Process::assertNothingRan();
    }

    #[DataProvider('incompleteWorkerConfigurations')]
    public function test_incomplete_worker_is_not_ready_even_if_legacy_database_configuration_exists(array $changes): void
    {
        Process::preventStrayProcesses();
        Process::fake([]);
        config()->set('accounting.staging.sqlserver_worker', [...$this->workerConfiguration(), ...$changes]);
        config()->set('database.connections.legacy_sqlserver', [
            'host' => 'legacy.private.example', 'database' => 'legacy', 'username' => 'reader',
        ]);
        $dataSource = DataSource::factory()->create([
            'key' => 'legacy_sqlserver',
            'access_mode' => 'read_only_worker',
        ]);

        $readiness = app(SourceReadinessService::class)->inspect($dataSource);

        $this->assertFalse($readiness['configured']);
        $this->assertSame('not_attempted', $readiness['network_status']);
        $this->assertSame('Konfigurasi sumber belum lengkap. Tidak ada koneksi yang dicoba.', $readiness['message']);
        Process::assertNothingRan();
    }

    public static function incompleteWorkerConfigurations(): array
    {
        return [
            'disabled worker' => [['enabled' => false]],
            'blank host' => [['ssh_host' => ' ']],
            'missing user' => [['ssh_user' => null]],
            'missing identity path' => [['ssh_identity_file' => '']],
            'missing python' => [['remote_python' => null]],
            'missing php' => [['remote_php' => '']],
            'missing source config' => [['remote_config' => '']],
            'port zero' => [['ssh_port' => 0]],
            'port too large' => [['ssh_port' => 65536]],
            'port not integer' => [['ssh_port' => 'invalid']],
        ];
    }

    private function workerConfiguration(): array
    {
        return [
            'enabled' => true,
            'ssh_host' => 'worker.private.example',
            'ssh_port' => 2222,
            'ssh_user' => 'private-worker-user',
            'ssh_identity_file' => '/private/worker-key',
            'remote_python' => '/usr/bin/python3',
            'remote_php' => '/usr/bin/php',
            'remote_config' => '/private/sources.php',
        ];
    }
}
