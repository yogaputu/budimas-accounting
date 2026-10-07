<?php

namespace Database\Seeders\Accounting;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\LegalEntity;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class AccountingChartSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach (config('accounting.entities') as $code => $name) {
            $legalEntity = LegalEntity::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'is_active' => true,
                ],
            );

            $this->seedTemplateFor($legalEntity);
        }
    }

    private function seedTemplateFor(LegalEntity $legalEntity): void
    {
        /** @var array<string, int> $accountIdsByCode */
        $accountIdsByCode = ChartOfAccount::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->pluck('id', 'code')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($this->template() as $definition) {
            $account = ChartOfAccount::query()->firstOrNew([
                'legal_entity_id' => $legalEntity->id,
                'code' => $definition['code'],
            ]);
            $isNew = ! $account->exists;

            $account->fill([
                'name' => $definition['name'],
                'category' => $definition['category'],
                'normal_balance' => $definition['normal_balance'],
                'parent_id' => $definition['parent_code'] === null
                    ? null
                    : $accountIdsByCode[$definition['parent_code']],
                'is_postable' => $definition['is_postable'],
            ]);

            if ($isNew) {
                $account->is_active = true;
            }

            $account->save();
            $accountIdsByCode[$definition['code']] = $account->id;
        }
    }

    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     category: string,
     *     normal_balance: string,
     *     parent_code: string|null,
     *     is_postable: bool
     * }>
     */
    private function template(): array
    {
        return [
            ['code' => '1000', 'name' => 'Aset', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => null, 'is_postable' => false],
            ['code' => '1100', 'name' => 'Aset Lancar', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1000', 'is_postable' => false],
            ['code' => '1101', 'name' => 'Kas Besar', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1100', 'is_postable' => true],
            ['code' => '1102', 'name' => 'Kas Kecil', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1100', 'is_postable' => true],
            ['code' => '1103', 'name' => 'Bank Operasional', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1100', 'is_postable' => true],
            ['code' => '1104', 'name' => 'Bank Penerimaan', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1100', 'is_postable' => true],
            ['code' => '1105', 'name' => 'Piutang Usaha', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1100', 'is_postable' => true],
            ['code' => '1106', 'name' => 'Cadangan Kerugian Piutang', 'category' => 'asset', 'normal_balance' => 'credit', 'parent_code' => '1100', 'is_postable' => true],
            ['code' => '1107', 'name' => 'Persediaan Barang Dagang', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1100', 'is_postable' => true],
            ['code' => '1108', 'name' => 'Persediaan Dalam Perjalanan', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1100', 'is_postable' => true],
            ['code' => '1109', 'name' => 'PPN Masukan', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1100', 'is_postable' => true],
            ['code' => '1110', 'name' => 'Uang Muka Pembelian', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1100', 'is_postable' => true],
            ['code' => '1111', 'name' => 'Biaya Dibayar Dimuka', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1100', 'is_postable' => true],
            ['code' => '1200', 'name' => 'Aset Tidak Lancar', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1000', 'is_postable' => false],
            ['code' => '1201', 'name' => 'Aset Tetap', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1200', 'is_postable' => false],
            ['code' => '1202', 'name' => 'Peralatan dan Inventaris', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1201', 'is_postable' => true],
            ['code' => '1203', 'name' => 'Kendaraan', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1201', 'is_postable' => true],
            ['code' => '1204', 'name' => 'Akumulasi Penyusutan', 'category' => 'asset', 'normal_balance' => 'credit', 'parent_code' => '1200', 'is_postable' => true],
            ['code' => '1205', 'name' => 'Aset Lain-lain', 'category' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1200', 'is_postable' => true],

            ['code' => '2000', 'name' => 'Kewajiban', 'category' => 'liability', 'normal_balance' => 'credit', 'parent_code' => null, 'is_postable' => false],
            ['code' => '2100', 'name' => 'Kewajiban Lancar', 'category' => 'liability', 'normal_balance' => 'credit', 'parent_code' => '2000', 'is_postable' => false],
            ['code' => '2101', 'name' => 'Utang Usaha', 'category' => 'liability', 'normal_balance' => 'credit', 'parent_code' => '2100', 'is_postable' => true],
            ['code' => '2102', 'name' => 'Utang Pajak', 'category' => 'liability', 'normal_balance' => 'credit', 'parent_code' => '2100', 'is_postable' => true],
            ['code' => '2103', 'name' => 'Uang Muka Pelanggan', 'category' => 'liability', 'normal_balance' => 'credit', 'parent_code' => '2100', 'is_postable' => true],
            ['code' => '2104', 'name' => 'Utang Biaya', 'category' => 'liability', 'normal_balance' => 'credit', 'parent_code' => '2100', 'is_postable' => true],
            ['code' => '2105', 'name' => 'Utang Gaji dan Tunjangan', 'category' => 'liability', 'normal_balance' => 'credit', 'parent_code' => '2100', 'is_postable' => true],
            ['code' => '2200', 'name' => 'Kewajiban Jangka Panjang', 'category' => 'liability', 'normal_balance' => 'credit', 'parent_code' => '2000', 'is_postable' => false],
            ['code' => '2201', 'name' => 'Utang Bank', 'category' => 'liability', 'normal_balance' => 'credit', 'parent_code' => '2200', 'is_postable' => true],

            ['code' => '3000', 'name' => 'Ekuitas', 'category' => 'equity', 'normal_balance' => 'credit', 'parent_code' => null, 'is_postable' => false],
            ['code' => '3100', 'name' => 'Modal Disetor', 'category' => 'equity', 'normal_balance' => 'credit', 'parent_code' => '3000', 'is_postable' => true],
            ['code' => '3200', 'name' => 'Laba Ditahan', 'category' => 'equity', 'normal_balance' => 'credit', 'parent_code' => '3000', 'is_postable' => true],
            ['code' => '3300', 'name' => 'Laba Tahun Berjalan', 'category' => 'equity', 'normal_balance' => 'credit', 'parent_code' => '3000', 'is_postable' => true],

            ['code' => '4000', 'name' => 'Pendapatan', 'category' => 'revenue', 'normal_balance' => 'credit', 'parent_code' => null, 'is_postable' => false],
            ['code' => '4100', 'name' => 'Penjualan Barang', 'category' => 'revenue', 'normal_balance' => 'credit', 'parent_code' => '4000', 'is_postable' => true],
            ['code' => '4101', 'name' => 'Retur Penjualan', 'category' => 'revenue', 'normal_balance' => 'debit', 'parent_code' => '4000', 'is_postable' => true],
            ['code' => '4102', 'name' => 'Potongan Penjualan', 'category' => 'revenue', 'normal_balance' => 'debit', 'parent_code' => '4000', 'is_postable' => true],
            ['code' => '4200', 'name' => 'Pendapatan Jasa', 'category' => 'revenue', 'normal_balance' => 'credit', 'parent_code' => '4000', 'is_postable' => true],

            ['code' => '5000', 'name' => 'Harga Pokok Penjualan', 'category' => 'cost_of_sales', 'normal_balance' => 'debit', 'parent_code' => null, 'is_postable' => false],
            ['code' => '5100', 'name' => 'Harga Pokok Barang Terjual', 'category' => 'cost_of_sales', 'normal_balance' => 'debit', 'parent_code' => '5000', 'is_postable' => true],
            ['code' => '5101', 'name' => 'Biaya Angkut Pembelian', 'category' => 'cost_of_sales', 'normal_balance' => 'debit', 'parent_code' => '5000', 'is_postable' => true],
            ['code' => '5102', 'name' => 'Selisih Persediaan', 'category' => 'cost_of_sales', 'normal_balance' => 'debit', 'parent_code' => '5000', 'is_postable' => true],

            ['code' => '6000', 'name' => 'Beban Operasional', 'category' => 'expense', 'normal_balance' => 'debit', 'parent_code' => null, 'is_postable' => false],
            ['code' => '6100', 'name' => 'Beban Penjualan', 'category' => 'expense', 'normal_balance' => 'debit', 'parent_code' => '6000', 'is_postable' => false],
            ['code' => '6101', 'name' => 'Beban Gaji Penjualan', 'category' => 'expense', 'normal_balance' => 'debit', 'parent_code' => '6100', 'is_postable' => true],
            ['code' => '6102', 'name' => 'Beban Pengiriman', 'category' => 'expense', 'normal_balance' => 'debit', 'parent_code' => '6100', 'is_postable' => true],
            ['code' => '6103', 'name' => 'Beban Promosi', 'category' => 'expense', 'normal_balance' => 'debit', 'parent_code' => '6100', 'is_postable' => true],
            ['code' => '6200', 'name' => 'Beban Umum dan Administrasi', 'category' => 'expense', 'normal_balance' => 'debit', 'parent_code' => '6000', 'is_postable' => false],
            ['code' => '6201', 'name' => 'Beban Gaji dan Tunjangan', 'category' => 'expense', 'normal_balance' => 'debit', 'parent_code' => '6200', 'is_postable' => true],
            ['code' => '6202', 'name' => 'Beban Sewa', 'category' => 'expense', 'normal_balance' => 'debit', 'parent_code' => '6200', 'is_postable' => true],
            ['code' => '6203', 'name' => 'Beban Utilitas', 'category' => 'expense', 'normal_balance' => 'debit', 'parent_code' => '6200', 'is_postable' => true],
            ['code' => '6204', 'name' => 'Beban Penyusutan', 'category' => 'expense', 'normal_balance' => 'debit', 'parent_code' => '6200', 'is_postable' => true],
            ['code' => '6205', 'name' => 'Beban Administrasi Bank', 'category' => 'expense', 'normal_balance' => 'debit', 'parent_code' => '6200', 'is_postable' => true],
            ['code' => '6206', 'name' => 'Beban Piutang Tak Tertagih', 'category' => 'expense', 'normal_balance' => 'debit', 'parent_code' => '6200', 'is_postable' => true],

            ['code' => '7000', 'name' => 'Pendapatan Lain-lain', 'category' => 'other_income', 'normal_balance' => 'credit', 'parent_code' => null, 'is_postable' => false],
            ['code' => '7100', 'name' => 'Pendapatan Bunga', 'category' => 'other_income', 'normal_balance' => 'credit', 'parent_code' => '7000', 'is_postable' => true],
            ['code' => '7101', 'name' => 'Keuntungan Selisih Kurs', 'category' => 'other_income', 'normal_balance' => 'credit', 'parent_code' => '7000', 'is_postable' => true],
            ['code' => '7102', 'name' => 'Pendapatan Lainnya', 'category' => 'other_income', 'normal_balance' => 'credit', 'parent_code' => '7000', 'is_postable' => true],

            ['code' => '8000', 'name' => 'Beban Lain-lain', 'category' => 'other_expense', 'normal_balance' => 'debit', 'parent_code' => null, 'is_postable' => false],
            ['code' => '8100', 'name' => 'Beban Bunga', 'category' => 'other_expense', 'normal_balance' => 'debit', 'parent_code' => '8000', 'is_postable' => true],
            ['code' => '8101', 'name' => 'Kerugian Selisih Kurs', 'category' => 'other_expense', 'normal_balance' => 'debit', 'parent_code' => '8000', 'is_postable' => true],
            ['code' => '8102', 'name' => 'Denda dan Sanksi Pajak', 'category' => 'other_expense', 'normal_balance' => 'debit', 'parent_code' => '8000', 'is_postable' => true],
        ];
    }
}
