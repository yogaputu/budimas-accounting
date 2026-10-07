<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0f172a">
        <title>Periode Accounting · Budimas Accounting</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-5 py-8 sm:px-8 lg:px-10">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-7 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-bold tracking-[0.18em] text-indigo-600">BUDIMAS ACCOUNTING · KONTROL</p>
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950">Periode Accounting</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Buka dan tutup periode per perusahaan. Periode tidak dapat ditutup selama masih terdapat jurnal yang belum diposting.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('accounting.dashboard') }}" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-indigo-300 hover:text-indigo-700">Dashboard</a>
                    @if ($canManageAccounts)
                        <a href="{{ route('accounting.accounts.index') }}" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-indigo-300 hover:text-indigo-700">Daftar akun</a>
                    @endif
                </div>
            </header>

            <main class="space-y-6 py-8">
                @if (session('status'))
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
                @endif

                @if ($errors->any())
                    <div class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
                        <p class="font-semibold">Periode belum dapat diproses.</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.8fr)]">
                    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="font-semibold text-slate-950">Daftar periode</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ $periods->total() }} periode tercatat.</p>
                            </div>
                            <form method="GET" action="{{ route('accounting.periods.index') }}" class="flex gap-2">
                                <select name="entity" class="rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Semua perusahaan</option>
                                    @foreach ($legalEntities as $legalEntity)
                                        <option value="{{ $legalEntity->id }}" @selected((int) $selectedLegalEntityId === $legalEntity->id)>{{ $legalEntity->code }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-indigo-300 hover:text-indigo-700">Filter</button>
                            </form>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-bold tracking-[0.1em] text-slate-500">
                                    <tr>
                                        <th scope="col" class="px-5 py-4">PERUSAHAAN</th>
                                        <th scope="col" class="px-5 py-4">KODE</th>
                                        <th scope="col" class="px-5 py-4">RENTANG TANGGAL</th>
                                        <th scope="col" class="px-5 py-4">STATUS</th>
                                        <th scope="col" class="px-5 py-4">DITUTUP OLEH</th>
                                        <th scope="col" class="px-5 py-4"><span class="sr-only">Aksi</span></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @forelse ($periods as $period)
                                        <tr class="hover:bg-slate-50/80">
                                            <td class="whitespace-nowrap px-5 py-4 text-xs font-semibold text-slate-600">{{ $period->legalEntity->code }}</td>
                                            <td class="whitespace-nowrap px-5 py-4 font-mono font-bold text-slate-900">{{ $period->code }}</td>
                                            <td class="whitespace-nowrap px-5 py-4 text-slate-700">{{ $period->starts_on->format('d M Y') }} — {{ $period->ends_on->format('d M Y') }}</td>
                                            <td class="whitespace-nowrap px-5 py-4">
                                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $period->isOpen() ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-200 text-slate-700' }}">{{ $period->isOpen() ? 'Terbuka' : 'Ditutup' }}</span>
                                            </td>
                                            <td class="px-5 py-4 text-xs text-slate-600">
                                                @if ($period->isClosed())
                                                    <span class="font-medium text-slate-700">{{ $period->closedBy?->name ?? '—' }}</span>
                                                    <span class="block text-slate-500">{{ $period->closed_at?->format('d M Y H:i') }}</span>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap px-5 py-4 text-right">
                                                @if ($period->isOpen())
                                                    <form method="POST" action="{{ route('accounting.periods.close', $period) }}">
                                                        @csrf
                                                        <button type="submit" class="text-sm font-semibold text-rose-700 transition hover:text-rose-900">Tutup periode</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('accounting.periods.reopen', $period) }}">
                                                        @csrf
                                                        <button type="submit" class="text-sm font-semibold text-indigo-700 transition hover:text-indigo-900">Buka kembali</button>
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="px-5 py-12 text-center text-sm text-slate-500">Belum ada periode untuk filter ini.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if ($periods->hasPages())
                            <div class="border-t border-slate-200 px-5 py-4">{{ $periods->links() }}</div>
                        @endif
                    </div>

                    <aside class="h-fit rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-xs font-bold tracking-[0.16em] text-indigo-600">PERIODE BARU</p>
                        <h2 class="mt-2 text-xl font-bold tracking-tight text-slate-950">Buka periode Accounting</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Setiap rentang tanggal pada perusahaan yang sama harus unik dan tidak boleh saling bertumpuk.</p>

                        <form method="POST" action="{{ route('accounting.periods.store') }}" class="mt-6 space-y-5">
                            @csrf
                            <label class="block">
                                <span class="text-xs font-bold tracking-[0.12em] text-slate-500">PERUSAHAAN</span>
                                <select name="legal_entity_id" class="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('legal_entity_id') border-rose-400 @enderror" required>
                                    <option value="">Pilih perusahaan</option>
                                    @foreach ($legalEntities as $legalEntity)
                                        <option value="{{ $legalEntity->id }}" @selected((int) old('legal_entity_id', $selectedLegalEntityId) === $legalEntity->id)>{{ $legalEntity->code }} · {{ $legalEntity->name }}</option>
                                    @endforeach
                                </select>
                                @error('legal_entity_id')<span class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                            </label>

                            <label class="block">
                                <span class="text-xs font-bold tracking-[0.12em] text-slate-500">KODE PERIODE</span>
                                <input type="text" name="code" value="{{ old('code', $suggestedPeriod['code']) }}" maxlength="16" placeholder="Contoh: 2026-09" class="mt-2 block w-full rounded-xl border-slate-300 font-mono text-sm shadow-sm placeholder:font-sans placeholder:text-slate-400 focus:border-indigo-500 focus:ring-indigo-500 @error('code') border-rose-400 @enderror" required>
                                @error('code')<span class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                            </label>

                            <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-1">
                                <label class="block">
                                    <span class="text-xs font-bold tracking-[0.12em] text-slate-500">MULAI</span>
                                    <input type="date" name="starts_on" value="{{ old('starts_on', $suggestedPeriod['starts_on']) }}" class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('starts_on') border-rose-400 @enderror" required>
                                    @error('starts_on')<span class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <label class="block">
                                    <span class="text-xs font-bold tracking-[0.12em] text-slate-500">SELESAI</span>
                                    <input type="date" name="ends_on" value="{{ old('ends_on', $suggestedPeriod['ends_on']) }}" class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('ends_on') border-rose-400 @enderror" required>
                                    @error('ends_on')<span class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                                </label>
                            </div>

                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">Buka periode</button>
                        </form>
                    </aside>
                </section>
            </main>
        </div>
    </body>
</html>
