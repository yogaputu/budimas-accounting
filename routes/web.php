<?php

use App\Http\Controllers\AccessControlController;
use App\Http\Controllers\Accounting\CashBankReportController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\PullErpStagingController;
use App\Http\Controllers\Accounting\PullSqlServerStagingController;
use App\Http\Controllers\Accounting\StagingDataController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\AccountingDashboardController;
use App\Http\Controllers\AccountingPeriodsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ChartOfAccountsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::get('/', AccountingDashboardController::class)
        ->middleware('permission:accounting.dashboard.view')
        ->name('accounting.dashboard');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/akses', [AccessControlController::class, 'index'])
        ->middleware('permission:access.manage,global')
        ->name('access.index');
    Route::put('/akses/pengguna/{user}/roles', [AccessControlController::class, 'updateUserRoles'])
        ->middleware('permission:access.manage,global')
        ->name('access.users.roles.update');
    Route::put('/akses/role/{role}/permissions', [AccessControlController::class, 'updateRolePermissions'])
        ->middleware('permission:access.manage,global')
        ->name('access.roles.permissions.update');

    Route::middleware('permission:journal.manage,global')->prefix('pembukuan')->group(function (): void {
        Route::get('/akun', [ChartOfAccountsController::class, 'index'])->name('accounting.accounts.index');
        Route::get('/akun/buat', [ChartOfAccountsController::class, 'create'])->name('accounting.accounts.create');
        Route::post('/akun', [ChartOfAccountsController::class, 'store'])->name('accounting.accounts.store');
        Route::get('/akun/{chartOfAccount}/edit', [ChartOfAccountsController::class, 'edit'])->name('accounting.accounts.edit');
        Route::put('/akun/{chartOfAccount}', [ChartOfAccountsController::class, 'update'])->name('accounting.accounts.update');
    });

    Route::prefix('pembukuan')->group(function (): void {
        Route::get('/jurnal', [JournalEntryController::class, 'index'])->name('accounting.journals.index');
        Route::get('/jurnal/buat', [JournalEntryController::class, 'create'])->name('accounting.journals.create');
        Route::post('/jurnal', [JournalEntryController::class, 'store'])->name('accounting.journals.store');
        Route::get('/jurnal/{journalEntry}', [JournalEntryController::class, 'show'])->name('accounting.journals.show');
        Route::post('/jurnal/{journalEntry}/post', [JournalEntryController::class, 'post'])->name('accounting.journals.post');
    });

    Route::middleware('permission:accounting.period.manage,global')->prefix('pembukuan')->group(function (): void {
        Route::get('/periode', [AccountingPeriodsController::class, 'index'])->name('accounting.periods.index');
        Route::post('/periode', [AccountingPeriodsController::class, 'store'])->name('accounting.periods.store');
        Route::post('/periode/{accountingPeriod}/tutup', [AccountingPeriodsController::class, 'close'])->name('accounting.periods.close');
        Route::post('/periode/{accountingPeriod}/buka-kembali', [AccountingPeriodsController::class, 'reopen'])->name('accounting.periods.reopen');
    });

    Route::get('/laporan/neraca-saldo', TrialBalanceController::class)
        ->name('accounting.reports.trial-balance');
    Route::get('/laporan/buku-besar', GeneralLedgerController::class)
        ->name('accounting.reports.general-ledger');
    Route::get('/laporan/kas-bank', CashBankReportController::class)
        ->name('accounting.reports.cash-bank');

    Route::get('/integrasi/staging', StagingDataController::class)
        ->name('accounting.staging.index');
    Route::post('/integrasi/staging/erp', PullErpStagingController::class)
        ->middleware(['permission:integration.sync.manage,global', 'permission:system.health.view,global', 'throttle:3,1'])
        ->name('accounting.staging.pull');
    Route::post('/integrasi/staging/sql-server', PullSqlServerStagingController::class)
        ->middleware(['permission:integration.sync.manage,global', 'permission:system.health.view,global', 'throttle:3,1'])
        ->name('accounting.staging.pull-sqlserver');
});
