<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0f172a">
        <title>Hak Akses · Budimas Accounting</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-5 py-8 sm:px-8 lg:px-10">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-7 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <a href="{{ route('accounting.dashboard') }}" class="text-xs font-bold tracking-[0.18em] text-indigo-600 transition hover:text-indigo-800">BUDIMAS ACCOUNTING</a>
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950">Hak akses</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Kelola role dan permission global. Scope per perusahaan atau cabang sudah disiapkan pada data assignment dan akan dipakai saat modul transaksi dibuka.</p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('accounting.dashboard') }}" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-indigo-300 hover:text-indigo-700">Dashboard</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-rose-300 hover:text-rose-700">Keluar</button>
                    </form>
                </div>
            </header>

            <main class="space-y-7 py-8">
                @if (session('status'))
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800" role="status">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800" role="alert">
                        {{ $errors->first() }}
                    </div>
                @endif

                <section class="rounded-3xl border border-indigo-100 bg-indigo-50 p-6 shadow-sm sm:p-7">
                    <p class="text-xs font-bold tracking-[0.18em] text-indigo-600">KONTROL AKSES</p>
                    <div class="mt-3 grid gap-5 lg:grid-cols-3">
                        <div>
                            <h2 class="font-semibold text-indigo-950">Tidak ada registrasi publik</h2>
                            <p class="mt-1 text-sm leading-6 text-indigo-800">Akun dibuat oleh administrator melalui proses terkontrol, lalu role diberikan secara eksplisit.</p>
                        </div>
                        <div>
                            <h2 class="font-semibold text-indigo-950">Super Admin terlindungi</h2>
                            <p class="mt-1 text-sm leading-6 text-indigo-800">Sistem menolak penghapusan Super Admin global terakhir dan permission-nya tidak dapat dikosongkan.</p>
                        </div>
                        <div>
                            <h2 class="font-semibold text-indigo-950">Audit perubahan</h2>
                            <p class="mt-1 text-sm leading-6 text-indigo-800">Setiap perubahan role dan permission direkam bersama akun pengubah, waktu, dan informasi request.</p>
                        </div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-6 py-5">
                        <p class="text-xs font-bold tracking-[0.16em] text-indigo-600">PENGGUNA</p>
                        <h2 class="mt-1 text-xl font-bold tracking-tight text-slate-950">Role pengguna global</h2>
                        <p class="mt-1 text-sm text-slate-600">Centang role yang diberikan pada seluruh scope Accounting. Role khusus per perusahaan/cabang akan ditambah sebagai assignment terpisah.</p>
                    </div>

                    <div class="divide-y divide-slate-200">
                        @forelse ($users as $managedUser)
                            <form method="POST" action="{{ route('access.users.roles.update', $managedUser) }}" class="px-6 py-5">
                                @csrf
                                @method('PUT')
                                <input name="is_active" type="hidden" value="0">
                                <div class="grid gap-5 xl:grid-cols-[minmax(220px,0.7fr)_minmax(0,1.5fr)_auto] xl:items-center">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <p class="font-semibold text-slate-950">{{ $managedUser->name }}</p>
                                            @if ($managedUser->is_active)
                                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200">Aktif</span>
                                            @else
                                                <span class="rounded-full bg-rose-50 px-2.5 py-1 text-[11px] font-bold text-rose-700 ring-1 ring-inset ring-rose-200">Nonaktif</span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-sm text-slate-600">{{ $managedUser->email }}</p>
                                        <p class="mt-2 text-xs text-slate-500">Login terakhir: {{ $managedUser->last_login_at?->translatedFormat('d M Y H:i') ?? 'Belum pernah' }}</p>
                                        <label class="mt-3 inline-flex cursor-pointer items-center gap-2 text-xs font-semibold text-slate-700">
                                            <input name="is_active" type="checkbox" value="1" @checked($managedUser->is_active) class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                            Akun aktif
                                        </label>
                                    </div>

                                    <fieldset class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                        <legend class="sr-only">Role {{ $managedUser->name }}</legend>
                                        @foreach ($roles as $role)
                                            <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 transition hover:border-indigo-200 hover:bg-indigo-50">
                                                <input
                                                    type="checkbox"
                                                    name="role_ids[]"
                                                    value="{{ $role->id }}"
                                                    @checked($managedUser->roleAssignments->contains('role_id', $role->id))
                                                    class="mt-0.5 size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                                >
                                                <span>
                                                    <span class="block text-sm font-semibold text-slate-800">{{ $role->name }}</span>
                                                    <span class="mt-0.5 block text-xs leading-4 text-slate-500">{{ $role->description }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </fieldset>

                                    <button type="submit" class="inline-flex justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-100">Simpan role</button>
                                </div>
                            </form>
                        @empty
                            <div class="px-6 py-12 text-center text-sm text-slate-500">Belum ada pengguna pada database Accounting.</div>
                        @endforelse
                    </div>

                    @if ($users->hasPages())
                        <div class="border-t border-slate-200 px-6 py-4">
                            {{ $users->links() }}
                        </div>
                    @endif
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
                    <div>
                        <p class="text-xs font-bold tracking-[0.16em] text-indigo-600">ROLE &amp; PERMISSION</p>
                        <h2 class="mt-1 text-xl font-bold tracking-tight text-slate-950">Matriks akses</h2>
                        <p class="mt-1 text-sm text-slate-600">Perubahan berlaku pada login berikutnya atau saat user membuka halaman berikutnya.</p>
                    </div>

                    <div class="mt-6 grid gap-5 xl:grid-cols-2">
                        @foreach ($roles as $role)
                            <article class="rounded-2xl border border-slate-200 p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="font-semibold text-slate-950">{{ $role->name }}</h3>
                                        <p class="mt-1 text-sm leading-5 text-slate-600">{{ $role->description }}</p>
                                    </div>
                                    @if ($role->code === 'super_admin')
                                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Dikunci sistem</span>
                                    @endif
                                </div>

                                @if ($role->code === 'super_admin')
                                    <p class="mt-5 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">Super Admin memiliki seluruh permission dan hanya dapat dikelola melalui assignment user.</p>
                                @else
                                    <form method="POST" action="{{ route('access.roles.permissions.update', $role) }}" class="mt-5">
                                        @csrf
                                        @method('PUT')
                                        <fieldset class="space-y-4">
                                            <legend class="sr-only">Permission {{ $role->name }}</legend>
                                            @foreach ($permissionsByModule as $module => $permissions)
                                                <div>
                                                    <p class="text-xs font-bold tracking-[0.14em] text-slate-500">{{ strtoupper($module) }}</p>
                                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                        @foreach ($permissions as $permission)
                                                            <label class="flex cursor-pointer gap-2.5 rounded-xl bg-slate-50 px-3 py-2.5 text-sm transition hover:bg-indigo-50">
                                                                <input
                                                                    type="checkbox"
                                                                    name="permission_ids[]"
                                                                    value="{{ $permission->id }}"
                                                                    @checked($role->permissions->contains('id', $permission->id))
                                                                    class="mt-0.5 size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                                                >
                                                                <span>
                                                                    <span class="block font-medium text-slate-800">{{ $permission->name }}</span>
                                                                    <span class="mt-0.5 block text-xs leading-4 text-slate-500">{{ $permission->description }}</span>
                                                                </span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endforeach
                                        </fieldset>
                                        <button type="submit" class="mt-5 inline-flex rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-100">Simpan permission</button>
                                    </form>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            </main>
        </div>
    </body>
</html>
