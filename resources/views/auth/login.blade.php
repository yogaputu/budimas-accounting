<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0f172a">
        <title>Masuk · Budimas Accounting</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-900 antialiased">
        <main class="relative isolate flex min-h-screen items-center justify-center overflow-hidden px-5 py-10 sm:px-8">
            <div class="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,_rgba(79,70,229,0.35),_transparent_38%),radial-gradient(circle_at_bottom_right,_rgba(16,185,129,0.18),_transparent_35%)]"></div>
            <div class="absolute inset-x-0 top-0 -z-10 h-px bg-white/10"></div>

            <section class="grid w-full max-w-5xl overflow-hidden rounded-3xl border border-white/10 bg-white shadow-2xl shadow-black/40 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="hidden bg-slate-950 p-10 text-white lg:block">
                    <div class="flex size-14 items-center justify-center rounded-2xl bg-indigo-500 text-sm font-black tracking-[0.2em] shadow-lg shadow-indigo-500/30">
                        BA
                    </div>
                    <p class="mt-12 text-xs font-bold tracking-[0.2em] text-indigo-300">BUDIMAS ACCOUNTING</p>
                    <h1 class="mt-4 max-w-md text-4xl font-bold tracking-tight">Accounting mandiri untuk BMM &amp; TMP.</h1>
                    <p class="mt-5 max-w-md text-base leading-7 text-slate-300">
                        Jurnal, kas, piutang, dan rekonsiliasi disimpan terpisah dari ERP dengan hak akses yang dapat dikendalikan per peran dan scope perusahaan.
                    </p>

                    <dl class="mt-12 space-y-4 border-t border-white/10 pt-7 text-sm">
                        <div class="flex gap-3">
                            <span class="mt-1 size-2.5 shrink-0 rounded-full bg-emerald-400"></span>
                            <div>
                                <dt class="font-semibold">Sumber data read-only</dt>
                                <dd class="mt-1 text-slate-400">ERP PostgreSQL dan SQL Server tidak dapat diposting dari sini.</dd>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <span class="mt-1 size-2.5 shrink-0 rounded-full bg-indigo-400"></span>
                            <div>
                                <dt class="font-semibold">Jejak audit akses</dt>
                                <dd class="mt-1 text-slate-400">Perubahan role dan permission tercatat untuk review.</dd>
                            </div>
                        </div>
                    </dl>
                </div>

                <div class="p-7 sm:p-10">
                    <div class="lg:hidden">
                        <div class="flex size-12 items-center justify-center rounded-2xl bg-indigo-600 text-sm font-black tracking-[0.2em] text-white shadow-lg shadow-indigo-200">BA</div>
                        <p class="mt-5 text-xs font-bold tracking-[0.2em] text-indigo-600">BUDIMAS ACCOUNTING</p>
                    </div>

                    <div class="mt-8 lg:mt-12">
                        <h2 class="text-2xl font-bold tracking-tight text-slate-950">Masuk ke Accounting</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">Gunakan akun yang telah diberi hak akses oleh Super Admin.</p>
                    </div>

                    @if ($errors->any())
                        <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <form class="mt-7 space-y-5" method="POST" action="{{ route('login.store') }}">
                        @csrf

                        <div>
                            <label for="email" class="text-sm font-semibold text-slate-800">Email</label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                value="{{ old('email') }}"
                                autocomplete="username"
                                required
                                autofocus
                                class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-950 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100"
                                placeholder="nama@perusahaan.co.id"
                            >
                        </div>

                        <div>
                            <label for="password" class="text-sm font-semibold text-slate-800">Kata sandi</label>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                required
                                class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-950 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100"
                                placeholder="Masukkan kata sandi"
                            >
                        </div>

                        <label class="flex items-center gap-3 text-sm text-slate-600">
                            <input name="remember" type="checkbox" value="1" class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            Ingat saya di perangkat ini
                        </label>

                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-4 py-3.5 text-sm font-semibold text-white shadow-lg shadow-indigo-200 transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200">
                            Masuk
                        </button>
                    </form>

                    <p class="mt-7 border-t border-slate-200 pt-5 text-xs leading-5 text-slate-500">
                        Belum memiliki akses? Hubungi Super Admin Accounting untuk penambahan user atau penyesuaian role.
                    </p>
                </div>
            </section>
        </main>
    </body>
</html>
