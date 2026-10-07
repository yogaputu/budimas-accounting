<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Kas &amp; Bank · Budimas Accounting</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-5 py-8 sm:px-8 lg:px-10">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-7 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    @if ($canViewDashboard)
                        <a href="{{ route('accounting.dashboard') }}" class="text-xs font-bold tracking-[0.2em] text-indigo-600">BUDIMAS ACCOUNTING</a>
                    @else
                        <p class="text-xs font-bold tracking-[0.2em] text-indigo-600">BUDIMAS ACCOUNTING</p>
                    @endif
                    <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Kas &amp; Bank</h1>
                    <p class="mt-1 text-sm text-slate-500">Mutasi akun kas dan bank dari jurnal yang sudah diposting.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('accounting.reports.trial-balance', ['legal_entity_id' => $selectedEntity->id, 'period_id' => $selectedPeriod?->id]) }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-indigo-300 hover:text-indigo-700">Neraca Saldo</a>
                    @if ($canManageJournals)
                        <a href="{{ route('accounting.journals.index', ['legal_entity_id' => $selectedEntity->id]) }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-indigo-300 hover:text-indigo-700">Jurnal Umum</a>
                    @endif
                    @if ($canManageAccounts)
                        <a href="{{ route('accounting.accounts.index', ['entity' => $selectedEntity->id]) }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:border-indigo-300 hover:text-indigo-700">Daftar Akun</a>
                    @endif
                </div>
            </header>

            <main class="space-y-6 py-8">
                <form method="GET" class="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                    <label class="block text-sm font-semibold text-slate-700">
                        Perusahaan
                        <select name="legal_entity_id" class="mt-2 w-full rounded-xl border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($entities as $entity)
                                <option value="{{ $entity->id }}" @selected($entity->id === $selectedEntity->id)>{{ $entity->code }} — {{ $entity->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">
                        Periode buku
                        <select name="period_id" class="mt-2 w-full rounded-xl border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @forelse ($periods as $period)
                                <option value="{{ $period->id }}" @selected($selectedPeriod?->id === $period->id)>{{ $period->code }} · {{ $period->starts_on->format('d M Y') }} — {{ $period->ends_on->format('d M Y') }} · {{ $period->status === 'open' ? 'Terbuka' : 'Tutup' }}</option>
                            @empty
                                <option value="">Belum ada periode buku</option>
                            @endforelse
                        </select>
                    </label>
                    <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Tampilkan</button>
                </form>

                @if ($selectedPeriod === null)
                    <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                        Belum ada periode buku untuk perusahaan ini. Buat periode terlebih dahulu sebelum melihat mutasi Kas &amp; Bank.
                    </section>
                @else
                    <section class="rounded-2xl border border-sky-200 bg-sky-50 px-5 py-4 text-sm leading-6 text-sky-900">
                        Laporan ini hanya menampilkan mutasi pada periode <strong>{{ $selectedPeriod->code }}</strong> dari jurnal yang telah diposting. Saldo pembuka dan saldo akhir belum dihitung pada tahap ini.
                    </section>

                    <section class="grid gap-4 md:grid-cols-3">
                        @foreach ([
                            ['Mutasi Debit', $totals['period_debit'], 'text-indigo-700', 'bg-indigo-50'],
                            ['Mutasi Kredit', $totals['period_credit'], 'text-violet-700', 'bg-violet-50'],
                            ['Mutasi Bersih', $totals['net_movement'], $totals['net_movement'] < 0 ? 'text-rose-700' : 'text-emerald-700', $totals['net_movement'] < 0 ? 'bg-rose-50' : 'bg-emerald-50'],
                        ] as [$label, $value, $tone, $background])
                            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-xs font-bold tracking-[0.14em] text-slate-500">{{ strtoupper($label) }}</p>
                                <p class="mt-3 text-xl font-bold tracking-tight {{ $tone }}">Rp {{ \App\Accounting\AccountingAmount::format($value) }}</p>
                                <span class="mt-3 inline-block rounded-full px-2.5 py-1 text-xs font-semibold {{ $tone }} {{ $background }}">{{ $selectedEntity->code }} · {{ $selectedPeriod->code }}</span>
                            </article>
                        @endforeach
                    </section>

                    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-2 border-b border-slate-200 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="font-bold text-slate-950">Ringkasan akun Kas &amp; Bank</h2>
                                <p class="mt-1 text-sm text-slate-500">Kas Besar, Kas Kecil, Bank Operasional, dan Bank Penerimaan.</p>
                            </div>
                            <span class="w-fit rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600">{{ $accounts->count() }} akun</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-bold tracking-[0.08em] text-slate-500">
                                    <tr>
                                        <th class="px-5 py-3">KODE</th>
                                        <th class="px-5 py-3">AKUN</th>
                                        <th class="px-5 py-3 text-right">MUTASI DEBIT</th>
                                        <th class="px-5 py-3 text-right">MUTASI KREDIT</th>
                                        <th class="px-5 py-3 text-right">MUTASI BERSIH</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    @forelse ($accounts as $account)
                                        <tr class="hover:bg-slate-50">
                                            <td class="whitespace-nowrap px-5 py-3 font-mono text-xs font-semibold text-slate-600">{{ $account->code }}</td>
                                            <td class="px-5 py-3">
                                                <p class="font-semibold text-slate-900">{{ $account->name }}</p>
                                                @unless ($account->is_active)
                                                    <p class="mt-1 text-xs font-medium text-amber-700">Akun tidak aktif</p>
                                                @endunless
                                            </td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($account->period_debit) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($account->period_credit) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right font-semibold {{ bccomp($account->net_movement, '0', 2) < 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ \App\Accounting\AccountingAmount::format($account->net_movement) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="px-5 py-12 text-center text-sm text-slate-500">Akun Kas &amp; Bank belum tersedia untuk perusahaan ini.</td></tr>
                                    @endforelse
                                </tbody>
                                @if ($accounts->isNotEmpty())
                                    <tfoot class="border-t-2 border-slate-300 bg-slate-50 font-bold text-slate-900">
                                        <tr>
                                            <td colspan="2" class="px-5 py-3">Total</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($totals['period_debit']) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($totals['period_credit']) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($totals['net_movement']) }}</td>
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-6 py-5">
                            <h2 class="font-bold text-slate-950">Pergerakan terbaru</h2>
                            <p class="mt-1 text-sm text-slate-500">Maksimal 30 baris akun Kas &amp; Bank dari jurnal yang sudah diposting pada periode ini.</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-bold tracking-[0.08em] text-slate-500">
                                    <tr>
                                        <th class="px-5 py-3">TANGGAL</th>
                                        <th class="px-5 py-3">JURNAL</th>
                                        <th class="px-5 py-3">AKUN</th>
                                        <th class="px-5 py-3">CABANG / CATATAN</th>
                                        <th class="px-5 py-3 text-right">DEBIT</th>
                                        <th class="px-5 py-3 text-right">KREDIT</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    @forelse ($recentMovements as $movement)
                                        <tr class="hover:bg-slate-50">
                                            <td class="whitespace-nowrap px-5 py-3">{{ $movement->entry_date->format('d M Y') }}</td>
                                            <td class="px-5 py-3">
                                                <p class="font-mono text-xs font-semibold text-slate-700">{{ $movement->entry_no }}</p>
                                                @if ($movement->journal_description)
                                                    <p class="mt-1 max-w-xs truncate text-xs text-slate-500">{{ $movement->journal_description }}</p>
                                                @endif
                                            </td>
                                            <td class="px-5 py-3"><span class="font-mono text-xs font-semibold text-slate-600">{{ $movement->account_code }}</span> <span class="font-semibold text-slate-900">{{ $movement->account_name }}</span></td>
                                            <td class="px-5 py-3">
                                                <p>{{ $movement->branch_label ?? '—' }}</p>
                                                @if ($movement->memo)
                                                    <p class="mt-1 max-w-xs truncate text-xs text-slate-500">{{ $movement->memo }}</p>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($movement->debit) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($movement->credit) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="px-5 py-12 text-center text-sm text-slate-500">Belum ada mutasi Kas &amp; Bank dari jurnal yang diposting pada periode ini.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif
            </main>
        </div>
    </body>
</html>
