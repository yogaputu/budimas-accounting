<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0f172a">
        <title>Daftar Akun · Budimas Accounting</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-5 py-8 sm:px-8 lg:px-10">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-7 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-bold tracking-[0.18em] text-indigo-600">BUDIMAS ACCOUNTING · MASTER</p>
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950">Daftar Akun</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Kelola struktur akun per perusahaan. Akun kelompok tidak dapat dipakai pada garis jurnal; akun posting digunakan saat pencatatan transaksi.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('accounting.dashboard') }}" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-indigo-300 hover:text-indigo-700">Dashboard</a>
                    @if ($canManagePeriods)
                        <a href="{{ route('accounting.periods.index') }}" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-indigo-300 hover:text-indigo-700">Periode Accounting</a>
                    @endif
                    <a href="{{ route('accounting.accounts.create', ['entity' => $selectedLegalEntityId]) }}" class="inline-flex items-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">Tambah akun</a>
                </div>
            </header>

            <main class="space-y-6 py-8">
                @if (session('status'))
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
                @endif

                @if ($errors->any())
                    <div class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
                        <p class="font-semibold">Perubahan belum dapat disimpan.</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <form method="GET" action="{{ route('accounting.accounts.index') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-[minmax(190px,1fr)_minmax(180px,0.8fr)_minmax(160px,0.7fr)_minmax(240px,1.25fr)_auto] xl:items-end">
                        <label class="block">
                            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">PERUSAHAAN</span>
                            <select name="entity" class="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Semua perusahaan</option>
                                @foreach ($legalEntities as $legalEntity)
                                    <option value="{{ $legalEntity->id }}" @selected((int) $selectedLegalEntityId === $legalEntity->id)>{{ $legalEntity->code }} · {{ $legalEntity->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">KATEGORI</span>
                            <select name="category" class="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Semua kategori</option>
                                @foreach ($categories as $key => $label)
                                    <option value="{{ $key }}" @selected(request('category') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">STATUS</span>
                            <select name="status" class="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Semua status</option>
                                <option value="active" @selected(request('status') === 'active')>Aktif</option>
                                <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">CARI KODE ATAU NAMA</span>
                            <input type="search" name="search" value="{{ request('search') }}" placeholder="Contoh: 1105 atau piutang" class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:ring-indigo-500">
                        </label>
                        <div class="flex gap-2">
                            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700">Terapkan</button>
                            <a href="{{ route('accounting.accounts.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400">Reset</a>
                        </div>
                    </form>
                </section>

                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-2 border-b border-slate-200 px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="font-semibold text-slate-950">Struktur akun</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ $accounts->total() }} akun ditemukan.</p>
                        </div>
                        <span class="w-fit rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">Template BMM &amp; TMP dapat disesuaikan</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-bold tracking-[0.1em] text-slate-500">
                                <tr>
                                    <th scope="col" class="px-5 py-4">PERUSAHAAN</th>
                                    <th scope="col" class="px-5 py-4">KODE</th>
                                    <th scope="col" class="px-5 py-4">NAMA AKUN</th>
                                    <th scope="col" class="px-5 py-4">INDUK</th>
                                    <th scope="col" class="px-5 py-4">KATEGORI</th>
                                    <th scope="col" class="px-5 py-4">SALDO NORMAL</th>
                                    <th scope="col" class="px-5 py-4">TIPE</th>
                                    <th scope="col" class="px-5 py-4">STATUS</th>
                                    <th scope="col" class="px-5 py-4"><span class="sr-only">Aksi</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @forelse ($accounts as $account)
                                    <tr class="hover:bg-slate-50/80">
                                        <td class="whitespace-nowrap px-5 py-4 text-xs font-semibold text-slate-600">{{ $account->legalEntity?->code }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 font-mono text-sm font-bold text-slate-900">{{ $account->code }}</td>
                                        <td class="px-5 py-4 font-semibold text-slate-900">{{ $account->name }}</td>
                                        <td class="px-5 py-4 text-slate-600">{{ $account->parent ? "{$account->parent->code} · {$account->parent->name}" : '—' }}</td>
                                        <td class="px-5 py-4"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ $categories[$account->category] ?? $account->category }}</span></td>
                                        <td class="whitespace-nowrap px-5 py-4 text-slate-700">{{ $account->normal_balance === 'debit' ? 'Debit' : 'Kredit' }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $account->is_postable ? 'bg-sky-50 text-sky-700' : 'bg-amber-50 text-amber-700' }}">{{ $account->is_postable ? 'Posting' : 'Kelompok' }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4">
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $account->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">{{ $account->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                                        </td>
                                        <td class="px-5 py-4 text-right"><a href="{{ route('accounting.accounts.edit', $account) }}" class="text-sm font-semibold text-indigo-700 transition hover:text-indigo-900">Ubah</a></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="px-5 py-12 text-center text-sm text-slate-500">Belum ada akun yang sesuai dengan filter.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($accounts->hasPages())
                        <div class="border-t border-slate-200 px-5 py-4">{{ $accounts->links() }}</div>
                    @endif
                </section>
            </main>
        </div>
    </body>
</html>
