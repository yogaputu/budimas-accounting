<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#111827">
        <title>Beranda Accounting · Budimas</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-5 py-7 sm:px-8 lg:px-10">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-600 text-sm font-black tracking-[0.18em] text-white shadow-lg shadow-indigo-200">BA</div>
                    <div>
                        <p class="text-xs font-bold tracking-[0.2em] text-indigo-600">BUDIMAS ACCOUNTING</p>
                        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Beranda Accounting</h1>
                        <p class="mt-1 text-sm text-slate-500">BMM &amp; TMP · pembukuan mandiri dengan sumber ERP read-only.</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    @if ($canViewStaging)
                        <a href="{{ route('accounting.staging.index') }}" class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm font-semibold text-sky-800 shadow-sm transition hover:bg-sky-100">Data staging</a>
                    @endif
                    @if ($canManageAccess)
                        <a href="{{ route('access.index') }}" class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-semibold text-indigo-700 shadow-sm transition hover:bg-indigo-100">Hak akses</a>
                    @endif
                    <a href="{{ route('accounting.health') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-indigo-300 hover:text-indigo-700">Status API</a>
                    <div class="hidden rounded-xl border border-slate-200 bg-white px-3 py-2 text-right shadow-sm sm:block">
                        <p class="max-w-44 truncate text-sm font-semibold text-slate-800">{{ $user->name }}</p>
                        <p class="max-w-44 truncate text-xs text-slate-500">{{ $activeRoleLabels->join(' · ') ?: 'Tanpa role' }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-rose-300 hover:text-rose-700">Keluar</button>
                    </form>
                </div>
            </header>

            <main class="space-y-7 py-8">
                @if (session('status'))
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
                @endif

                <section class="overflow-hidden rounded-3xl bg-slate-950 px-6 py-7 text-white shadow-xl shadow-slate-300 sm:px-8">
                    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                        <div>
                            <p class="text-xs font-bold tracking-[0.18em] text-indigo-300">MASTER → JURNAL → LAPORAN</p>
                            <h2 class="mt-3 max-w-3xl text-2xl font-semibold tracking-tight sm:text-3xl">Mulai pembukuan dari Daftar Akun, lalu catat jurnal yang seimbang.</h2>
                            <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300 sm:text-base">Struktur awal mengikuti praktik pembukuan umum: Daftar Akun, periode buku, jurnal, buku besar, dan neraca saldo. Data sumber dari ERP nantinya hanya dibaca dan direkonsiliasi sebelum dibuat jurnal.</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-4">
                            <p class="text-xs font-semibold tracking-[0.16em] text-slate-400">DATABASE AKTIF</p>
                            <p class="mt-2 font-mono text-lg font-semibold text-white">{{ $databaseConnection }}</p>
                            <p class="mt-1 text-xs text-slate-400">MySQL Accounting lokal terpisah dari database ERP.</p>
                        </div>
                    </div>
                </section>

                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ([
                        ['Daftar akun aktif', $activeAccountCount, 'COA BMM & TMP', 'text-indigo-700', 'bg-indigo-50'],
                        ['Periode terbuka', $openPeriodCount, 'Siap menerima jurnal', 'text-emerald-700', 'bg-emerald-50'],
                        ['Jurnal draft', $draftJournalCount, 'Menunggu peninjauan', 'text-amber-700', 'bg-amber-50'],
                        ['Jurnal diposting', $postedJournalCount, 'Terekam pada buku besar', 'text-sky-700', 'bg-sky-50'],
                    ] as [$label, $value, $detail, $tone, $background])
                        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold tracking-[0.14em] text-slate-500">{{ strtoupper($label) }}</p>
                            <div class="mt-3 flex items-end justify-between gap-3">
                                <p class="text-3xl font-bold tracking-tight text-slate-950">{{ number_format($value) }}</p>
                                <span class="rounded-xl px-2.5 py-1 text-xs font-bold {{ $tone }} {{ $background }}">Aktif</span>
                            </div>
                            <p class="mt-2 text-sm text-slate-500">{{ $detail }}</p>
                        </article>
                    @endforeach
                </section>

                <section class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(280px,0.65fr)]">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="text-xs font-bold tracking-[0.16em] text-indigo-600">AKSI CEPAT</p>
                                <h2 class="mt-2 text-xl font-bold tracking-tight text-slate-950">Mulai dari data yang terkendali</h2>
                            </div>
                            <span class="w-fit rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600">Tahap 1 Accounting</span>
                        </div>

                        <div class="mt-6 grid gap-3 sm:grid-cols-2">
                            @if ($canViewStaging)
                                <a href="{{ route('accounting.staging.index') }}" class="group rounded-2xl border border-sky-200 bg-sky-50 p-4 transition hover:border-sky-400 hover:bg-sky-100">
                                    <p class="font-semibold text-sky-950">Data Staging</p>
                                    <p class="mt-1 text-sm leading-5 text-sky-900">Tinjau snapshot ERP dan legacy yang sudah disimpan secara read-only sebelum direkonsiliasi.</p>
                                    <span class="mt-4 inline-block text-sm font-semibold text-sky-700">Buka data staging →</span>
                                </a>
                            @endif
                            @if ($canManageAccounts)
                                <a href="{{ route('accounting.accounts.index') }}" class="group rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-indigo-300 hover:bg-indigo-50">
                                    <p class="font-semibold text-slate-950 group-hover:text-indigo-800">Daftar Akun</p>
                                    <p class="mt-1 text-sm leading-5 text-slate-600">Atur kode, kategori, saldo normal, serta akun induk BMM/TMP.</p>
                                    <span class="mt-4 inline-block text-sm font-semibold text-indigo-700">Kelola akun →</span>
                                </a>
                            @endif
                            @if ($canManagePeriods)
                                <a href="{{ route('accounting.periods.index') }}" class="group rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-indigo-300 hover:bg-indigo-50">
                                    <p class="font-semibold text-slate-950 group-hover:text-indigo-800">Periode Buku</p>
                                    <p class="mt-1 text-sm leading-5 text-slate-600">Buka atau tutup periode setelah semua jurnal pada periode tersebut selesai.</p>
                                    <span class="mt-4 inline-block text-sm font-semibold text-indigo-700">Kelola periode →</span>
                                </a>
                            @endif
                            @if ($canManageJournals)
                                <a href="{{ route('accounting.journals.create') }}" class="group rounded-2xl border border-indigo-200 bg-indigo-50 p-4 transition hover:border-indigo-400 hover:bg-indigo-100">
                                    <p class="font-semibold text-indigo-950">Buat Jurnal Umum</p>
                                    <p class="mt-1 text-sm leading-5 text-indigo-800">Catat minimal dua baris; debit dan kredit harus selalu sama.</p>
                                    <span class="mt-4 inline-block text-sm font-semibold text-indigo-700">Buat jurnal →</span>
                                </a>
                            @endif
                            @if ($canViewReports)
                                <a href="{{ route('accounting.reports.trial-balance') }}" class="group rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-indigo-300 hover:bg-indigo-50">
                                    <p class="font-semibold text-slate-950 group-hover:text-indigo-800">Neraca Saldo</p>
                                    <p class="mt-1 text-sm leading-5 text-slate-600">Tinjau mutasi debit/kredit dari jurnal yang telah diposting.</p>
                                    <span class="mt-4 inline-block text-sm font-semibold text-indigo-700">Lihat laporan →</span>
                                </a>
                                <a href="{{ route('accounting.reports.general-ledger') }}" class="group rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-indigo-300 hover:bg-indigo-50">
                                    <p class="font-semibold text-slate-950 group-hover:text-indigo-800">Buku Besar</p>
                                    <p class="mt-1 text-sm leading-5 text-slate-600">Telusuri setiap jurnal posted dan saldo berjalan per akun.</p>
                                    <span class="mt-4 inline-block text-sm font-semibold text-indigo-700">Lihat laporan →</span>
                                </a>
                                <a href="{{ route('accounting.reports.cash-bank') }}" class="group rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-indigo-300 hover:bg-indigo-50">
                                    <p class="font-semibold text-slate-950 group-hover:text-indigo-800">Kas &amp; Bank</p>
                                    <p class="mt-1 text-sm leading-5 text-slate-600">Tinjau mutasi Kas Besar, Kas Kecil, dan rekening bank per periode.</p>
                                    <span class="mt-4 inline-block text-sm font-semibold text-indigo-700">Lihat laporan →</span>
                                </a>
                            @endif
                        </div>
                    </div>

                    <aside class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-xs font-bold tracking-[0.16em] text-indigo-600">SCOPE AWAL</p>
                        <h2 class="mt-2 text-xl font-bold tracking-tight text-slate-950">Perusahaan &amp; sumber data</h2>
                        <div class="mt-5 space-y-3">
                            @foreach ($legalEntities as $legalEntity)
                                <div class="rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-inset ring-slate-200">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="font-semibold text-slate-900">{{ $legalEntity->code }}</p>
                                        <span class="text-xs font-medium text-slate-500">{{ $legalEntity->branches_count }} cabang</span>
                                    </div>
                                    <p class="mt-1 text-sm text-slate-600">{{ $legalEntity->name }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-6 border-t border-slate-200 pt-5">
                            <p class="text-xs font-bold tracking-[0.16em] text-slate-500">SUMBER TERDAFTAR</p>
                            <div class="mt-3 space-y-3">
                                @foreach ($dataSources as $dataSource)
                                    <div class="flex gap-3">
                                        <span class="mt-1.5 size-2.5 shrink-0 rounded-full bg-indigo-500"></span>
                                        <div>
                                            <p class="text-sm font-semibold text-slate-800">{{ $dataSource->name }}</p>
                                            <p class="text-xs text-slate-500">{{ $dataSource->access_mode === 'read_only' ? 'Read-only' : 'Worker read-only' }}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </aside>
                </section>

                <section class="rounded-3xl border border-amber-200 bg-amber-50 px-6 py-5">
                    <p class="font-semibold text-amber-950">Kontrol sebelum posting</p>
                    <p class="mt-1 text-sm leading-6 text-amber-900">Template akun ini adalah fondasi awal. Verifikasi kode, pemetaan pajak, saldo pembuka, dan akun per cabang bersama Finance sebelum jurnal transaksi operasional diposting.</p>
                </section>
            </main>
        </div>
    </body>
</html>
