<?php

namespace App\Console\Commands;

use App\Accounting\ErpStagingPullException;
use App\Accounting\ErpStagingPullService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

#[Signature('accounting:stage-erp
    {--entity=BMM : Kode perusahaan ERP: BMM atau TMP}
    {--dataset=all : all, customers, sales_invoices, payments, atau returns}
    {--limit=100 : Maksimal record untuk setiap dataset (1-100)}')]
#[Description('Stage a bounded ERP PostgreSQL snapshot through a READ ONLY transaction')]
final class StageErpReadOnly extends Command
{
    public function handle(ErpStagingPullService $erpStagingPullService): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($limit === false) {
            $this->components->error('Limit harus berupa angka bulat antara 1 dan 100.');

            return self::INVALID;
        }

        try {
            $result = $erpStagingPullService->pull(
                entityCode: (string) $this->option('entity'),
                dataset: (string) $this->option('dataset'),
                limit: $limit,
            );
        } catch (InvalidArgumentException|ErpStagingPullException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->components->error((string) collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        } catch (Throwable $exception) {
            Log::error('Accounting ERP staging pull failed.', [
                'exception_class' => $exception::class,
            ]);
            $this->components->error('Staging ERP gagal diproses. Tidak ada perubahan yang dikirim ke ERP.');

            return self::FAILURE;
        }

        $this->components->info('Snapshot ERP read-only berhasil disimpan ke staging Accounting.');
        $this->table(
            ['Perusahaan', 'Dataset', 'Batas/dataset', 'Cabang', 'Tanpa cabang', 'Dibaca', 'Di-upsert', 'Sync run'],
            [[
                $result['entity'],
                $result['dataset'],
                (string) $result['limit'],
                (string) $result['branch_count'],
                (string) $result['unmapped_branch_count'],
                (string) $result['read_count'],
                (string) $result['upsert_count'],
                (string) $result['sync_run_id'],
            ]],
        );

        foreach ($result['counts'] as $dataset => $count) {
            $this->line("- {$dataset}: {$count} record");
        }

        if ($result['unmapped_branch_codes'] !== []) {
            $this->components->warn(
                'Beberapa record hanya distage di level perusahaan karena cabang sumber tidak ada di master aktif: '
                .implode(', ', $result['unmapped_branch_codes']).'.',
            );
        }

        return self::SUCCESS;
    }
}
