<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0f172a">
        <title>Ubah Akun · Budimas Accounting</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <main class="mx-auto max-w-4xl px-5 py-8 sm:px-8 lg:px-10">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-bold tracking-[0.18em] text-indigo-600">BUDIMAS ACCOUNTING · MASTER</p>
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950">Ubah akun</h1>
                    <p class="mt-2 text-sm text-slate-600">{{ $account->code }} · {{ $account->name }}</p>
                </div>
                <a href="{{ route('accounting.accounts.index', ['entity' => $account->legal_entity_id]) }}" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-indigo-300 hover:text-indigo-700">Kembali ke daftar akun</a>
            </div>

            @include('accounting.accounts._form', [
                'formAction' => route('accounting.accounts.update', $account),
                'formMethod' => 'PUT',
                'submitLabel' => 'Simpan perubahan',
            ])
        </main>
    </body>
</html>
