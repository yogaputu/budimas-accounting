<?php

namespace App;

enum PermissionCode: string
{
    case AccountingDashboardView = 'accounting.dashboard.view';
    case JournalManage = 'journal.manage';
    case CashManage = 'cash.manage';
    case ReceivablesManage = 'receivables.manage';
    case ReconciliationManage = 'reconciliation.manage';
    case PeriodManage = 'accounting.period.manage';
    case ReportView = 'reports.view';
    case AccessManage = 'access.manage';
    case SyncManage = 'integration.sync.manage';
    case SystemHealthView = 'system.health.view';

    public function label(): string
    {
        return match ($this) {
            self::AccountingDashboardView => 'Lihat dashboard Accounting',
            self::JournalManage => 'Kelola jurnal',
            self::CashManage => 'Kelola kas',
            self::ReceivablesManage => 'Kelola piutang',
            self::ReconciliationManage => 'Kelola rekonsiliasi',
            self::PeriodManage => 'Kelola periode Accounting',
            self::ReportView => 'Lihat laporan',
            self::AccessManage => 'Kelola hak akses',
            self::SyncManage => 'Kelola sinkronisasi data',
            self::SystemHealthView => 'Lihat kesehatan integrasi',
        };
    }

    public function module(): string
    {
        return match ($this) {
            self::AccountingDashboardView, self::PeriodManage => 'Accounting',
            self::JournalManage => 'Jurnal',
            self::CashManage => 'Kas',
            self::ReceivablesManage => 'Piutang',
            self::ReconciliationManage => 'Rekonsiliasi',
            self::ReportView => 'Laporan',
            self::AccessManage => 'Akses',
            self::SyncManage => 'Integrasi',
            self::SystemHealthView => 'Sistem',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AccountingDashboardView => 'Membuka ringkasan operasional Accounting.',
            self::JournalManage => 'Membuat, meninjau, dan memposting jurnal.',
            self::CashManage => 'Mencatat kas kecil, kas besar, dan transaksi kas.',
            self::ReceivablesManage => 'Mencatat dan mencocokkan piutang customer.',
            self::ReconciliationManage => 'Mencocokkan transaksi dengan mutasi bank atau sumber lain.',
            self::PeriodManage => 'Membuka, menutup, dan mengendalikan periode Accounting.',
            self::ReportView => 'Membuka laporan Accounting sesuai scope akses.',
            self::AccessManage => 'Mengatur peran dan hak akses pengguna.',
            self::SyncManage => 'Menjalankan atau meninjau sinkronisasi sumber data.',
            self::SystemHealthView => 'Melihat status sumber data dan integrasi.',
        };
    }
}
