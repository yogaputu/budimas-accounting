<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Neraca Saldo · Budimas Accounting</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-5 py-8 sm:px-8 lg:px-10">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-7 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <a href="{{ route('accounting.dashboard') }}" class="text-xs font-bold tracking-[0.2em] text-indigo-600">BUDIMAS ACCOUNTING</a>
                    <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Neraca Saldo</h1>
                    <p class="mt-1 text-sm text-slate-500">Ringkasan mutasi debit dan kredit dari jurnal yang sudah diposting.</p>
                </div>
                <div class="flex flex-wrap gap-3">
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
                        Belum ada periode buku untuk perusahaan ini. Buat periode terlebih dahulu sebelum melihat neraca saldo.
                    </section>
                @else
                    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ([
                            ['Mutasi Debit', $totals['period_debit'], 'text-indigo-700'],
                            ['Mutasi Kredit', $totals['period_credit'], 'text-indigo-700'],
                            ['Saldo Debit', $totals['balance_debit'], 'text-emerald-700'],
                            ['Saldo Kredit', $totals['balance_credit'], 'text-emerald-700'],
                        ] as [$label, $value, $tone])
                            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-xs font-bold tracking-[0.14em] text-slate-500">{{ strtoupper($label) }}</p>
                                <p class="mt-3 text-xl font-bold tracking-tight {{ $tone }}">Rp {{ \App\Accounting\AccountingAmount::format($value) }}</p>
                            </article>
                        @endforeach
                    </section>

                    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-2 border-b border-slate-200 px-6 py-5 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="font-bold text-slate-950">{{ $selectedEntity->code }} · {{ $selectedPeriod->code }}</h2>
                                <p class="mt-1 text-sm text-slate-500">Periode {{ $selectedPeriod->starts_on->format('d M Y') }} hingga {{ $selectedPeriod->ends_on->format('d M Y') }}. Saldo pembuka belum ditambahkan pada tahap ini.</p>
                            </div>
                            @if (bccomp($totals['period_debit'], $totals['period_credit'], 2) === 0)
                                <span class="w-fit rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200">Mutasi seimbang</span>
                            @else
                                <span class="w-fit rounded-full bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-700 ring-1 ring-inset ring-rose-200">Perlu ditinjau</span>
                            @endif
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-bold tracking-[0.08em] text-slate-500">
                                    <tr>
                                        <th class="px-5 py-3">KODE</th>
                                        <th class="px-5 py-3">NAMA AKUN</th>
                                        <th class="px-5 py-3">KATEGORI</th>
                                        <th class="px-5 py-3 text-right">MUTASI DEBIT</th>
                                        <th class="px-5 py-3 text-right">MUTASI KREDIT</th>
                                        <th class="px-5 py-3 text-right">SALDO DEBIT</th>
                                        <th class="px-5 py-3 text-right">SALDO KREDIT</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    @forelse ($accounts as $account)
                                        <tr class="hover:bg-slate-50">
                                            <td class="whitespace-nowrap px-5 py-3 font-mono text-xs font-semibold text-slate-600">{{ $account->code }}</td>
                                            <td class="px-5 py-3 font-semibold text-slate-900">{{ $account->name }}</td>
                                            <td class="px-5 py-3"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ str($account->category)->replace('_', ' ')->title() }}</span></td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($account->period_debit) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($account->period_credit) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right font-semibold">{{ \App\Accounting\AccountingAmount::format($account->balance_debit) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right font-semibold">{{ \App\Accounting\AccountingAmount::format($account->balance_credit) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="px-5 py-12 text-center text-sm text-slate-500">Belum ada akun untuk perusahaan ini.</td></tr>
                                    @endforelse
                                </tbody>
                                @if ($accounts->isNotEmpty())
                                    <tfoot class="border-t-2 border-slate-300 bg-slate-50 font-bold text-slate-900">
                                        <tr>
                                            <td colspan="3" class="px-5 py-3">Total</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($totals['period_debit']) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($totals['period_credit']) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($totals['balance_debit']) }}</td>
                                            <td class="whitespace-nowrap px-5 py-3 text-right">{{ \App\Accounting\AccountingAmount::format($totals['balance_credit']) }}</td>
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
