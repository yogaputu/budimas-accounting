<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0f172a">
        <title>{{ $journalEntry->entry_no }} · Budimas Accounting</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-5 py-8 sm:px-8 lg:px-10">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-7 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <a href="{{ route('accounting.journals.index', ['legal_entity_id' => $journalEntry->legal_entity_id]) }}" class="text-xs font-bold tracking-[0.18em] text-indigo-600 transition hover:text-indigo-800">← KEMBALI KE JURNAL UMUM</a>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <h1 class="font-mono text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">{{ $journalEntry->entry_no }}</h1>
                        @if ($journalEntry->isPosted())
                            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700">Posted</span>
                        @else
                            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800">Draft</span>
                        @endif
                    </div>
                    <p class="mt-2 text-sm text-slate-600">{{ $journalEntry->legalEntity->code }} · {{ $journalEntry->entry_date->translatedFormat('d M Y') }} · {{ $journalEntry->accountingPeriod->code }}</p>
                </div>

                @if ($canPost)
                    <form method="POST" action="{{ route('accounting.journals.post', $journalEntry) }}" onsubmit="return confirm('Posting jurnal akan mengunci data. Lanjutkan?');">
                        @csrf
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 sm:w-auto">Posting Jurnal</button>
                    </form>
                @endif
            </header>

            <main class="space-y-6 py-8">
                @if (session('status'))
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
                        <p class="font-bold">Jurnal tidak dapat diposting.</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold tracking-[0.14em] text-slate-500">PERUSAHAAN</p>
                        <p class="mt-2 font-semibold text-slate-950">{{ $journalEntry->legalEntity->code }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ $journalEntry->legalEntity->name }}</p>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold tracking-[0.14em] text-slate-500">CABANG</p>
                        <p class="mt-2 font-semibold text-slate-950">{{ $journalEntry->branch?->code ?: 'Semua / tanpa cabang' }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ $journalEntry->branch?->name ?: 'Jurnal tingkat perusahaan' }}</p>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold tracking-[0.14em] text-slate-500">TOTAL DEBIT</p>
                        <p class="mt-2 text-xl font-bold tabular-nums text-slate-950">Rp {{ \App\Accounting\AccountingAmount::format((string) $journalEntry->total_debit) }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ $journalEntry->lines->count() }} baris jurnal</p>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold tracking-[0.14em] text-slate-500">TOTAL KREDIT</p>
                        <p class="mt-2 text-xl font-bold tabular-nums text-slate-950">Rp {{ \App\Accounting\AccountingAmount::format((string) $journalEntry->total_credit) }}</p>
                        @if ($journalEntry->isPosted())
                            <p class="mt-1 text-sm text-emerald-700">Diposting oleh {{ $journalEntry->postedBy?->name ?: 'Sistem' }}</p>
                        @else
                            <p class="mt-1 text-sm text-amber-700">Menunggu posting</p>
                        @endif
                    </article>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <p class="text-xs font-bold tracking-[0.14em] text-indigo-600">KETERANGAN</p>
                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $journalEntry->description ?: 'Tidak ada keterangan tambahan.' }}</p>
                </section>

                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-1 border-b border-slate-200 px-5 py-5 sm:px-6">
                        <p class="text-xs font-bold tracking-[0.14em] text-indigo-600">RINCIAN JURNAL</p>
                        <h2 class="text-lg font-bold text-slate-950">Baris debit dan kredit</h2>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-[900px] w-full divide-y divide-slate-200 text-left text-sm">
                            <thead class="bg-slate-50 text-xs font-bold tracking-[0.1em] text-slate-500">
                                <tr>
                                    <th scope="col" class="w-16 px-5 py-3.5 text-center">#</th>
                                    <th scope="col" class="px-5 py-3.5">AKUN</th>
                                    <th scope="col" class="px-5 py-3.5">CABANG</th>
                                    <th scope="col" class="px-5 py-3.5">MEMO</th>
                                    <th scope="col" class="px-5 py-3.5 text-right">DEBIT</th>
                                    <th scope="col" class="px-5 py-3.5 text-right">KREDIT</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($journalEntry->lines as $journalLine)
                                    <tr>
                                        <td class="px-5 py-4 text-center font-medium text-slate-500">{{ $journalLine->line_no }}</td>
                                        <td class="px-5 py-4">
                                            <p class="font-mono text-xs font-semibold text-slate-700">{{ $journalLine->chartOfAccount->code }}</p>
                                            <p class="mt-1 font-medium text-slate-900">{{ $journalLine->chartOfAccount->name }}</p>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">{{ $journalLine->branch?->code ?: '—' }}</td>
                                        <td class="max-w-xs px-5 py-4 text-slate-700">{{ $journalLine->memo ?: '—' }}</td>
                                        <td class="px-5 py-4 text-right font-medium tabular-nums text-slate-800">{{ bccomp((string) $journalLine->debit, '0.00', 2) === 1 ? 'Rp '.\App\Accounting\AccountingAmount::format((string) $journalLine->debit) : '—' }}</td>
                                        <td class="px-5 py-4 text-right font-medium tabular-nums text-slate-800">{{ bccomp((string) $journalLine->credit, '0.00', 2) === 1 ? 'Rp '.\App\Accounting\AccountingAmount::format((string) $journalLine->credit) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t-2 border-slate-200 bg-slate-50 font-bold text-slate-950">
                                <tr>
                                    <td colspan="4" class="px-5 py-4 text-right">TOTAL</td>
                                    <td class="px-5 py-4 text-right tabular-nums">Rp {{ \App\Accounting\AccountingAmount::format((string) $journalEntry->total_debit) }}</td>
                                    <td class="px-5 py-4 text-right tabular-nums">Rp {{ \App\Accounting\AccountingAmount::format((string) $journalEntry->total_credit) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                @if ($journalEntry->isPosted())
                    <section class="rounded-3xl border border-emerald-200 bg-emerald-50 px-5 py-5 text-sm text-emerald-800 sm:px-6">
                        <p class="font-bold">Jurnal telah diposting dan dikunci.</p>
                        <p class="mt-1">Perubahan harus dilakukan melalui jurnal koreksi baru agar jejak audit tetap lengkap.</p>
                    </section>
                @elseif (! $canPost)
                    <section class="rounded-3xl border border-amber-200 bg-amber-50 px-5 py-5 text-sm text-amber-800 sm:px-6">
                        <p class="font-bold">Draft belum dapat diposting.</p>
                        <p class="mt-1">Pastikan periode masih terbuka, akun valid, dan total debit-kredit seimbang. Server akan memeriksa ulang saat tombol posting ditekan.</p>
                    </section>
                @endif
            </main>
        </div>
    </body>
</html>
