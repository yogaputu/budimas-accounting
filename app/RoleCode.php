<?php

namespace App;

enum RoleCode: string
{
    case SuperAdmin = 'super_admin';
    case AdminAccounting = 'admin_accounting';
    case Finance = 'finance';
    case Cashier = 'cashier';
    case Auditor = 'auditor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::AdminAccounting => 'Admin Accounting',
            self::Finance => 'Finance',
            self::Cashier => 'Kasir',
            self::Auditor => 'Auditor',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Akses penuh lintas entitas dan pengaturan akses.',
            self::AdminAccounting => 'Mengelola proses dan master Accounting tanpa mengubah Super Admin.',
            self::Finance => 'Mengelola jurnal, piutang, rekonsiliasi, dan pelaporan Finance.',
            self::Cashier => 'Mengelola penerimaan dan pengeluaran kas.',
            self::Auditor => 'Melihat data, laporan, dan jejak audit tanpa mengubah transaksi.',
            self::Viewer => 'Akses baca dashboard dan laporan yang diizinkan.',
        };
    }
}
