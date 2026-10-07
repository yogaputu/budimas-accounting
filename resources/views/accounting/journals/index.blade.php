<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0f172a">
        <title>Jurnal Umum · Budimas Accounting</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-5 py-8 sm:px-8 lg:px-10">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-7 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <a href="{{ route('accounting.dashboard') }}" class="text-xs font-bold tracking-[0.18em] text-indigo-600 transition hover:text-indigo-800">BUDIMAS ACCOUNTING</a>
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950">Jurnal Umum</h1>
                    <p class="mt-2 text-sm text-slate-600">Buat draft jurnal manual, periksa keseimbangan debit-kredit, lalu posting ke buku besar.</p>
                </div>
                <a href="{{ route('accounting.journals.create', ['legal_entity_id' => $selectedLegalEntity->id]) }}" class="inline-flex shrink-0 items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                    + Buat Jurnal Manual
                </a>
            </header>

            <main class="space-y-6 py-8">
                @if (session('status'))
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800">
                        {{ session('status') }}
                    </div>
                @endif

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <form method="GET" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
                        <label class="block">
                            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">PERUSAHAAN</span>
                            <select name="legal_entity_id" class="mt-2 w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach ($legalEntities as $legalEntity)
                                    <option value="{{ $legalEntity->id }}" @selected($legalEntity->is($selectedLegalEntity))>{{ $legalEntity->code }} — {{ $legalEntity->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">STATUS</span>
                            <select name="status" class="mt-2 w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Semua status</option>
                                <option value="draft" @selected($selectedStatus === 'draft')>Draft</option>
                                <option value="posted" @selected($selectedStatus === 'posted')>Posted</option>
                            </select>
                        </label>

                        <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-indigo-300 hover:text-indigo-700">Terapkan filter</button>
                    </form>
                </section>

                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-2 border-b border-slate-200 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div>
                            <p class="text-xs font-bold tracking-[0.15em] text-indigo-600">BUKU HARIAN</p>
                            <h2 class="mt-1 text-lg font-bold text-slate-950">Jurnal {{ $selectedLegalEntity->code }}</h2>
                        </div>
                        <p class="text-sm text-slate-500">{{ $journalEntries->total() }} jurnal ditemukan</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                            <thead class="bg-slate-50 text-xs font-bold tracking-[0.1em] text-slate-500">
                                <tr>
                                    <th scope="col" class="px-5 py-3.5 sm:px-6">NO. JURNAL</th>
                                    <th scope="col" class="px-5 py-3.5">TANGGAL / PERIODE</th>
                                    <th scope="col" class="px-5 py-3.5">KETERANGAN</th>
                                    <th scope="col" class="px-5 py-3.5 text-right">DEBIT</th>
                                    <th scope="col" class="px-5 py-3.5 text-right">KREDIT</th>
                                    <th scope="col" class="px-5 py-3.5">STATUS</th>
                                    <th scope="col" class="px-5 py-3.5 sm:px-6"><span class="sr-only">Detail</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @forelse ($journalEntries as $journalEntry)
                                    <tr class="transition hover:bg-slate-50">
                                        <td class="px-5 py-4 font-mono text-xs font-semibold text-slate-800 sm:px-6">{{ $journalEntry->entry_no }}</td>
                                        <td class="px-5 py-4 text-slate-700">
                                            <p>{{ $journalEntry->entry_date->translatedFormat('d M Y') }}</p>
                                            <p class="mt-1 text-xs text-slate-500">{{ $journalEntry->accountingPeriod->code }}@if ($journalEntry->branch) · {{ $journalEntry->branch->code }}@endif</p>
                                        </td>
                                        <td class="max-w-xs px-5 py-4 text-slate-700">{{ $journalEntry->description ?: '—' }}</td>
                                        <td class="px-5 py-4 text-right font-medium tabular-nums text-slate-800">Rp {{ \App\Accounting\AccountingAmount::format((string) $journalEntry->total_debit) }}</td>
                                        <td class="px-5 py-4 text-right font-medium tabular-nums text-slate-800">Rp {{ \App\Accounting\AccountingAmount::format((string) $journalEntry->total_credit) }}</td>
                                        <td class="px-5 py-4">
                                            @if ($journalEntry->isPosted())
                                                <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-700">Posted</span>
                                            @else
                                                <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800">Draft</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-right sm:px-6">
                                            <a href="{{ route('accounting.journals.show', $journalEntry) }}" class="text-sm font-semibold text-indigo-700 transition hover:text-indigo-900">Lihat</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-6 py-12 text-center text-sm text-slate-500">Belum ada jurnal pada filter ini.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($journalEntries->hasPages())
                        <div class="border-t border-slate-200 px-5 py-4 sm:px-6">
                            {{ $journalEntries->links() }}
                        </div>
                    @endif
                </section>
            </main>
        </div>
    </body>
</html>
