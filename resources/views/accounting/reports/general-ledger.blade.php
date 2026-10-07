<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Buku Besar · Budimas Accounting</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-5 py-8 sm:px-8 lg:px-10">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-7 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <a href="{{ route('accounting.dashboard') }}" class="text-xs font-bold tracking-[0.2em] text-indigo-600">BUDIMAS ACCOUNTING</a>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Buku Besar</h1>
                    <p class="mt-1 text-sm text-slate-500">Telusuri jurnal yang sudah diposting per akun, periode, dan perusahaan.</p>
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
                <form method="GET" class="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)_auto] lg:items-end">
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
                                <option value="{{ $period->id }}" @selected($selectedPeriod?->id === $period->id)>{{ $period->code }} · {{ $period->starts_on->format('d M Y') }} — {{ $period->ends_on->format('d M Y') }}</option>
                            @empty
                                <option value="">Belum ada periode buku</option>
                            @endforelse
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-slate-700">
                        Akun posting
                        <select name="chart_of_account_id" class="mt-2 w-full rounded-xl border-slate-300 bg-white text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @forelse ($accounts as $account)
                                <option value="{{ $account->id }}" @selected($selectedAccount?->id === $account->id)>{{ $account->code }} — {{ $account->name }}</option>
                            @empty
                                <option value="">Belum ada akun posting</option>
                            @endforelse
                        </select>
                    </label>
                    <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Tampilkan</button>
                </form>

                @if ($selectedPeriod === null || $selectedAccount === null)
                    <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                        @if ($selectedPeriod === null)
                            Belum ada periode buku untuk perusahaan ini. Buat periode terlebih dahulu sebelum membuka Buku Besar.
                        @else
                            Belum ada akun posting aktif untuk perusahaan ini.
                        @endif
                    </section>
                @else
                    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ([
                            ['Saldo pembuka', $openingBalance, 'text-slate-800'],
                            ['Mutasi debit', $totals['debit'], 'text-indigo-700'],
                            ['Mutasi kredit', $totals['credit'], 'text-indigo-700'],
                            ['Saldo berjalan', $totals['closing_balance'], 'text-emerald-700'],
                        ] as [$label, $value, $tone])
                            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-xs font-bold tracking-[0.14em] text-slate-500">{{ strtoupper($label) }}</p>
                                <p class="mt-3 text-xl font-bold tracking-tight {{ $tone }}">Rp {{ \App\Accounting\AccountingAmount::format($value) }}</p>
                            </article>
                        @endforeach
                    </section>

                    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-3 border-b border-slate-200 px-6 py-5 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-xs font-bold tracking-[0.14em] text-indigo-600">{{ $selectedEntity->code }} · {{ $selectedPeriod->code }}</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">{{ $selectedAccount->code }} — {{ $selectedAccount->name }}</h2>
                                <p class="mt-1 text-sm text-slate-500">Saldo normal {{ $selectedAccount->normal_balance === 'credit' ? 'kredit' : 'debit' }}. Hanya jurnal berstatus diposting yang ditampilkan.</p>
                            </div>
                            <span class="w-fit rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600">{{ $lines->count() }} baris jurnal</span>
                        </div>
                        <div class="border-b border-sky-100 bg-sky-50 px-6 py-3 text-sm text-sky-900">
                            Saldo pembuka untuk versi awal ini ditampilkan sebagai Rp 0,00. Saldo historis lintas-periode akan ditambahkan saat migrasi saldo pembuka disetujui Finance.
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-bold tracking-[0.08em] text-slate-500">
                                    <tr>
                                        <th class="px-5 py-3">TANGGAL</th>
                                        <th class="px-5 py-3">NO. JURNAL</th>
                                        <th class="px-5 py-3">KETERANGAN</th>
                                        <th class="px-5 py-3">MEMO BARIS</th>
                                        <th class="px-5 py-3 text-right">DEBIT</th>
                                        <th class="px-5 py-3 text-right">KREDIT</th>
                                        <th class="px-5 py-3 text-right">SALDO BERJALAN</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    @forelse ($lines as $line)
                                        <tr class="hover:bg-slate-50">
                                            <td class="whitespace-nowrap px-5 py-3">{{ \Illuminate\Support\Carbon::parse($line->journal_entry_date)->format('d M Y') }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 font-mono text-xs font-semibold text-slate-700">{{ $line->journal_entry_no }}</td>
                                            <td class="px-5 py-3 font-medium text-slate-900">{{ $line->journal_entry_description ?: '—' }}</td>
                                            <td class="px-5 py-3 text-slate-600">{{ $line->memo ?: '—' }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($line->debit) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($line->credit) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right font-semibold text-slate-900">{{ \App\Accounting\AccountingAmount::format($line->running_balance) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="px-5 py-12 text-center text-sm text-slate-500">Belum ada jurnal diposting untuk akun dan periode ini.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                                @if ($lines->isNotEmpty())
                                    <tfoot class="border-t-2 border-slate-300 bg-slate-50 font-bold text-slate-900">
                                        <tr>
                                            <td colspan="4" class="px-5 py-3">Total mutasi</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($totals['debit']) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($totals['credit']) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($totals['closing_balance']) }}</td>
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>
                    </section>
                @endif
            </main>
        </div>
    </body>
</html>
