<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0f172a">
        <title>Data Staging · Budimas Accounting</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-5 py-8 sm:px-8 lg:px-10">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-7 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    @if ($canViewDashboard)
                        <a href="{{ route('accounting.dashboard') }}" class="text-xs font-bold tracking-[0.18em] text-indigo-600">BUDIMAS ACCOUNTING · INTEGRASI</a>
                    @else
                        <p class="text-xs font-bold tracking-[0.18em] text-indigo-600">BUDIMAS ACCOUNTING · INTEGRASI</p>
                    @endif
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950">Data Staging</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Tarik dan tinjau salinan data ERP atau SQL Server sebelum dipetakan atau direkonsiliasi di Accounting.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    @if ($canViewDashboard)
                        <a href="{{ route('accounting.dashboard') }}" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-indigo-300 hover:text-indigo-700">Dashboard</a>
                    @endif
                    <a href="{{ route('accounting.staging.index') }}" class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-700">Muat ulang</a>
                </div>
            </header>

            <main class="space-y-6 py-8">
                <section class="rounded-3xl border border-sky-200 bg-sky-50 px-6 py-5 text-sky-950">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-bold tracking-[0.16em] text-sky-700">SALINAN DATA SUMBER</p>
                            <h2 class="mt-2 text-lg font-bold">Data ERP dan SQL Server dibaca dan disimpan sebagai salinan di Accounting.</h2>
                            <p class="mt-1 max-w-4xl text-sm leading-6 text-sky-900">Penarikan data tidak mengubah sumber dan tidak membuat jurnal otomatis. Salinan tersedia untuk pemeriksaan dan rekonsiliasi; nilai sensitif pada preview disamarkan.</p>
                        </div>
                        <span class="w-fit shrink-0 rounded-full bg-white px-3 py-1.5 text-xs font-bold text-sky-800 ring-1 ring-inset ring-sky-200">{{ $scopeLabel }}</span>
                    </div>
                </section>

                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ([
                        ['REKAMAN STAGING', $summary['records'], 'Baris sumber dalam scope aktif', 'text-indigo-700', 'bg-indigo-50'],
                        ['DISINKRONKAN HARI INI', $summary['synced_today'], 'Berdasarkan waktu tersimpan di Accounting', 'text-sky-700', 'bg-sky-50'],
                        ['SUMBER AKTIF', $summary['active_sources'], 'Terdaftar sebagai sumber read-only', 'text-emerald-700', 'bg-emerald-50'],
                        ['PERLU DITINJAU', $summary['failed_sources'], 'Run sumber terakhir berstatus gagal', 'text-rose-700', 'bg-rose-50'],
                    ] as [$label, $value, $detail, $tone, $background])
                        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-xs font-bold tracking-[0.14em] text-slate-500">{{ $label }}</p>
                            <div class="mt-3 flex items-end justify-between gap-3">
                                <p class="text-3xl font-bold tracking-tight text-slate-950">{{ number_format($value) }}</p>
                                <span class="rounded-xl px-2.5 py-1 text-xs font-bold {{ $tone }} {{ $background }}">Read-only</span>
                            </div>
                            <p class="mt-2 text-sm text-slate-500">{{ $detail }}</p>
                        </article>
                    @endforeach
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <form method="GET" action="{{ route('accounting.staging.index') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-[minmax(180px,0.9fr)_minmax(180px,0.9fr)_minmax(180px,0.9fr)_minmax(240px,1.25fr)_auto] xl:items-end">
                        <label class="block">
                            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">PERUSAHAAN</span>
                            <select name="legal_entity_id" class="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @if ($entities->count() > 1)
                                    <option value="" @selected($filters['legal_entity_id'] === null)>Semua perusahaan dalam scope</option>
                                @endif
                                @foreach ($entities as $entity)
                                    <option value="{{ $entity->id }}" @selected($filters['legal_entity_id'] === $entity->id)>{{ $entity->code }} · {{ $entity->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">SUMBER DATA</span>
                            <select name="data_source_id" class="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Semua sumber aktif</option>
                                @foreach ($sources as $source)
                                    <option value="{{ $source->id }}" @selected($filters['data_source_id'] === $source->id)>{{ $source->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">TIPE DATA</span>
                            <select name="external_type" class="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">Semua tipe data</option>
                                @foreach ($datasetOptions as $externalType => $datasetLabel)
                                    <option value="{{ $externalType }}" @selected($filters['external_type'] === $externalType)>{{ $datasetLabel }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">CARI KUNCI SUMBER</span>
                            <input type="search" name="search" value="{{ $filters['search'] }}" maxlength="100" placeholder="Contoh: INV-2026-001" class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:ring-indigo-500">
                        </label>
                        <div class="flex gap-2">
                            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700">Terapkan</button>
                            <a href="{{ route('accounting.staging.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400">Reset</a>
                        </div>
                    </form>
                </section>

                <section aria-labelledby="staging-pull-heading" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-bold tracking-[0.16em] text-indigo-600">PERBARUI SALINAN</p>
                            <h2 id="staging-pull-heading" class="mt-2 text-xl font-bold tracking-tight text-slate-950">Tarik Data ERP</h2>
                            <p class="mt-1 max-w-4xl text-sm leading-6 text-slate-600">Pilih perusahaan dan jenis data untuk memperbarui salinan di Accounting. Setiap penarikan membaca hingga 100 rekaman.</p>
                        </div>
                        <span class="w-fit shrink-0 rounded-full bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-700">Tanpa jurnal otomatis</span>
                    </div>

                    @if ($canManageSync)
                        @php
                            $pullResult = session('staging_pull_result');
                            $erpErrors = $errors->getBag('default');
                            $hasSqlServerOldInput = array_key_exists('source_key', session()->getOldInput());
                            $pullLimit = $hasSqlServerOldInput ? 100 : old('limit', 100);
                            $pullDataset = $hasSqlServerOldInput ? 'sales_invoices' : old('dataset', 'sales_invoices');
                            $defaultPullEntityCode = $syncEntities->firstWhere('code', $selectedEntity?->code)?->code ?? $syncEntities->first()?->code;
                        @endphp
                        @if ($pullResult)
                            <div role="status" class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-900">
                                <p class="font-semibold">Penarikan data ERP selesai.</p>
                                <p class="mt-1 leading-6">{{ $pullResult['entity_code'] }} · {{ $pullDatasetOptions[$pullResult['dataset']] ?? $pullResult['dataset'] }} · Batas {{ number_format($pullResult['limit']) }} rekaman</p>
                                <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                                    <div>
                                        <dt class="text-xs font-bold tracking-[0.1em] text-emerald-700">DIBACA DARI ERP</dt>
                                        <dd class="mt-1 text-xl font-bold">{{ number_format($pullResult['read_count']) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-bold tracking-[0.1em] text-emerald-700">DISIMPAN / DIPERBARUI</dt>
                                        <dd class="mt-1 text-xl font-bold">{{ number_format($pullResult['upsert_count']) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-bold tracking-[0.1em] text-emerald-700">CABANG</dt>
                                        <dd class="mt-1 text-xl font-bold">{{ number_format($pullResult['branch_count']) }}</dd>
                                    </div>
                                </dl>
                                <p class="mt-4 text-xs text-emerald-800">Referensi sinkronisasi: {{ $pullResult['sync_run_id'] }}</p>
                            </div>
                            @if ($pullResult['unmapped_branch_count'] > 0)
                                <div role="alert" class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-900">
                                    <p class="font-semibold">{{ number_format($pullResult['unmapped_branch_count']) }} rekaman belum memiliki pemetaan cabang.</p>
                                    <p>Periksa pemetaan cabang sebelum menggunakan salinan data untuk rekonsiliasi.</p>
                                    @if ($pullResult['unmapped_branch_codes'])
                                        <p class="mt-1 break-words">Kode cabang: {{ implode(', ', $pullResult['unmapped_branch_codes']) }}</p>
                                    @endif
                                </div>
                            @endif
                        @endif

                        @if ($erpErrors->any())
                            <div role="alert" class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
                                <p class="font-semibold">Penarikan data belum berhasil. Periksa informasi berikut.</p>
                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                    @foreach ($erpErrors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('accounting.staging.pull') }}" data-staging-pull-form class="mt-5 space-y-5">
                            @csrf
                            <div class="grid gap-4 md:grid-cols-3">
                                <label class="block">
                                    <span class="text-xs font-bold tracking-[0.12em] text-slate-500">PERUSAHAAN</span>
                                    <select name="entity_code" required aria-invalid="{{ $errors->has('entity_code') ? 'true' : 'false' }}" @error('entity_code') aria-describedby="staging-entity-error" @enderror class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('entity_code') border-rose-400 @enderror">
                                        <option value="">Pilih perusahaan</option>
                                        @foreach ($syncEntities as $syncEntity)
                                            <option value="{{ $syncEntity->code }}" @selected(old('entity_code', $defaultPullEntityCode) === $syncEntity->code)>{{ $syncEntity->code }} · {{ $syncEntity->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('entity_code')<span id="staging-entity-error" class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <label class="block">
                                    <span class="text-xs font-bold tracking-[0.12em] text-slate-500">JENIS DATA</span>
                                    <select name="dataset" required aria-invalid="{{ $errors->has('dataset') ? 'true' : 'false' }}" @error('dataset') aria-describedby="staging-dataset-error" @enderror class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('dataset') border-rose-400 @enderror">
                                        @foreach ($pullDatasetOptions as $dataset => $datasetLabel)
                                            <option value="{{ $dataset }}" @selected($pullDataset === $dataset)>{{ $datasetLabel }}</option>
                                        @endforeach
                                    </select>
                                    @error('dataset')<span id="staging-dataset-error" class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <label class="block">
                                    <span class="text-xs font-bold tracking-[0.12em] text-slate-500">BATAS REKAMAN</span>
                                    <input type="number" name="limit" value="{{ is_scalar($pullLimit) ? $pullLimit : 100 }}" min="1" max="100" step="1" required aria-invalid="{{ $errors->has('limit') ? 'true' : 'false' }}" aria-describedby="staging-limit-help{{ $errors->has('limit') ? ' staging-limit-error' : '' }}" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('limit') border-rose-400 @enderror">
                                    <span id="staging-limit-help" class="mt-2 block text-xs leading-5 text-slate-500">1–100 rekaman per penarikan.</span>
                                    @error('limit')<span id="staging-limit-error" class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                                </label>
                            </div>
                            @if ($syncEntities->isEmpty())
                                <p class="text-sm text-amber-800">Belum ada perusahaan BMM atau TMP aktif yang dapat dipilih.</p>
                            @endif
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <button type="submit" data-staging-pull-submit data-loading-label="Sedang menarik data…" @disabled($syncEntities->isEmpty()) class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-wait disabled:opacity-60">Tarik Data ERP</button>
                                <p data-staging-pull-status role="status" aria-live="polite" class="text-sm leading-6 text-slate-600"></p>
                            </div>
                        </form>
                    @else
                        <p class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-medium text-amber-900">Akses operator integrasi diperlukan untuk menarik data ERP. Anda tetap dapat meninjau salinan data sesuai akses perusahaan Anda.</p>
                    @endif
                </section>

                <section aria-labelledby="sqlserver-pull-heading" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-bold tracking-[0.16em] text-sky-700">PERBARUI SALINAN LEGACY</p>
                            <h2 id="sqlserver-pull-heading" class="mt-2 text-xl font-bold tracking-tight text-slate-950">Tarik Data SQL Server</h2>
                            <p class="mt-1 max-w-4xl text-sm leading-6 text-slate-600">Pilih sumber perusahaan dan cabang untuk membaca data legacy dan menyimpan salinannya di Accounting. Setiap penarikan membaca hingga 100 rekaman tanpa mengubah data sumber atau membuat jurnal otomatis.</p>
                        </div>
                        <span class="w-fit shrink-0 rounded-full bg-sky-50 px-3 py-1.5 text-xs font-bold text-sky-700">Tanpa jurnal otomatis</span>
                    </div>

                    @if ($canManageSync)
                        @php
                            $sqlServerResult = session('sqlserver_pull_result');
                            $sqlServerErrors = $errors->getBag('sqlserverPull');
                            $defaultSqlServerSourceKey = collect($sqlServerSources)->where('entity_code', $selectedEntity?->code)->keys()->first() ?? array_key_first($sqlServerSources);
                            $sqlServerSourceKey = $hasSqlServerOldInput ? old('source_key') : $defaultSqlServerSourceKey;
                            $sqlServerDataset = $hasSqlServerOldInput ? old('dataset', 'sales_invoices') : 'sales_invoices';
                            $sqlServerLimit = $hasSqlServerOldInput ? old('limit', 100) : 100;
                            $sqlServerPullDisabled = ! $sqlServerWorkerConfigured || empty($sqlServerSources);
                        @endphp

                        @if ($sqlServerResult)
                            <div role="status" class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-900">
                                <p class="font-semibold">Penarikan data SQL Server selesai.</p>
                                <p class="mt-1 leading-6">{{ $sqlServerResult['source_label'] }} · {{ $sqlServerDatasetOptions[$sqlServerResult['dataset']] ?? $sqlServerResult['dataset'] }}</p>
                                <p class="mt-1 leading-6">{{ $sqlServerResult['entity_code'] }} · Cabang {{ $sqlServerResult['branch_code'] }} · Batas {{ number_format($sqlServerResult['limit']) }} rekaman</p>
                                <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                                    <div>
                                        <dt class="text-xs font-bold tracking-[0.1em] text-emerald-700">DIBACA DARI SQL SERVER</dt>
                                        <dd class="mt-1 text-xl font-bold">{{ number_format($sqlServerResult['read_count']) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-bold tracking-[0.1em] text-emerald-700">DISIMPAN / DIPERBARUI</dt>
                                        <dd class="mt-1 text-xl font-bold">{{ number_format($sqlServerResult['upsert_count']) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-bold tracking-[0.1em] text-emerald-700">CABANG</dt>
                                        <dd class="mt-1 text-xl font-bold">{{ number_format($sqlServerResult['branch_count']) }}</dd>
                                    </div>
                                </dl>
                                <p class="mt-4 text-xs text-emerald-800">Referensi sinkronisasi: {{ $sqlServerResult['sync_run_id'] }}</p>
                            </div>
                        @endif

                        @if ($sqlServerErrors->any())
                            <div role="alert" class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
                                <p class="font-semibold">Penarikan data SQL Server belum berhasil. Periksa informasi berikut.</p>
                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                    @foreach ($sqlServerErrors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if (! $sqlServerWorkerConfigured)
                            <p class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-900">Koneksi penarikan data SQL Server belum siap. Hubungi pengelola integrasi untuk mengaktifkannya. Salinan yang sudah tersimpan tetap dapat ditinjau.</p>
                        @elseif (empty($sqlServerSources))
                            <p class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-900">Belum ada sumber SQL Server untuk perusahaan dan cabang aktif yang dapat dipilih.</p>
                        @endif

                        <form method="POST" action="{{ route('accounting.staging.pull-sqlserver') }}" data-staging-pull-form class="mt-5 space-y-5">
                            @csrf
                            <div class="grid gap-4 md:grid-cols-3">
                                <label class="block">
                                    <span class="text-xs font-bold tracking-[0.12em] text-slate-500">PERUSAHAAN / CABANG SUMBER</span>
                                    <select name="source_key" required @disabled($sqlServerPullDisabled) aria-invalid="{{ $sqlServerErrors->has('source_key') ? 'true' : 'false' }}" @error('source_key', 'sqlserverPull') aria-describedby="sqlserver-source-error" @enderror class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 disabled:bg-slate-100 disabled:text-slate-500 @error('source_key', 'sqlserverPull') border-rose-400 @enderror">
                                        <option value="">Pilih perusahaan dan cabang</option>
                                        @foreach ($sqlServerSources as $sourceKey => $sqlServerSource)
                                            <option value="{{ $sourceKey }}" @selected($sqlServerSourceKey === $sourceKey)>{{ $sqlServerSource['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('source_key', 'sqlserverPull')<span id="sqlserver-source-error" class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <label class="block">
                                    <span class="text-xs font-bold tracking-[0.12em] text-slate-500">JENIS DATA</span>
                                    <select name="dataset" required @disabled($sqlServerPullDisabled) aria-invalid="{{ $sqlServerErrors->has('dataset') ? 'true' : 'false' }}" @error('dataset', 'sqlserverPull') aria-describedby="sqlserver-dataset-error" @enderror class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 disabled:bg-slate-100 disabled:text-slate-500 @error('dataset', 'sqlserverPull') border-rose-400 @enderror">
                                        @foreach ($sqlServerDatasetOptions as $dataset => $datasetLabel)
                                            <option value="{{ $dataset }}" @selected($sqlServerDataset === $dataset)>{{ $datasetLabel }}</option>
                                        @endforeach
                                    </select>
                                    @error('dataset', 'sqlserverPull')<span id="sqlserver-dataset-error" class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <label class="block">
                                    <span class="text-xs font-bold tracking-[0.12em] text-slate-500">BATAS REKAMAN</span>
                                    <input type="number" name="limit" value="{{ is_scalar($sqlServerLimit) ? $sqlServerLimit : 100 }}" min="1" max="100" step="1" required @disabled($sqlServerPullDisabled) aria-invalid="{{ $sqlServerErrors->has('limit') ? 'true' : 'false' }}" aria-describedby="sqlserver-limit-help{{ $sqlServerErrors->has('limit') ? ' sqlserver-limit-error' : '' }}" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 disabled:bg-slate-100 disabled:text-slate-500 @error('limit', 'sqlserverPull') border-rose-400 @enderror">
                                    <span id="sqlserver-limit-help" class="mt-2 block text-xs leading-5 text-slate-500">1–100 rekaman per penarikan.</span>
                                    @error('limit', 'sqlserverPull')<span id="sqlserver-limit-error" class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                                </label>
                            </div>
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <button type="submit" data-staging-pull-submit data-loading-label="Sedang menarik data SQL Server…" @disabled($sqlServerPullDisabled) class="inline-flex items-center justify-center rounded-xl bg-sky-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-600 disabled:cursor-not-allowed disabled:opacity-60">Tarik Data SQL Server</button>
                                <p data-staging-pull-status role="status" aria-live="polite" class="text-sm leading-6 text-slate-600"></p>
                            </div>
                        </form>
                    @else
                        <p class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-medium text-amber-900">Akses operator integrasi diperlukan untuk menarik data SQL Server. Anda tetap dapat meninjau salinan data sesuai akses perusahaan Anda.</p>
                    @endif
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-xs font-bold tracking-[0.16em] text-indigo-600">STATUS SUMBER</p>
                            <h2 class="mt-2 text-xl font-bold tracking-tight text-slate-950">Kesiapan dan run sinkronisasi terakhir</h2>
                            <p class="mt-1 text-sm leading-6 text-slate-500">Status koneksi di bawah tidak membuka koneksi ke ERP/SQL Server. Ini hanya menunjukkan konfigurasi aman dan hasil run yang sudah tersimpan.</p>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 lg:grid-cols-2">
                        @foreach ($sourceStatuses as $sourceStatus)
                            @php($latestRun = $sourceStatus['latest_run'])
                            <article class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="font-semibold text-slate-950">{{ $sourceStatus['name'] }}</p>
                                        <p class="mt-1 font-mono text-xs text-slate-500">{{ $sourceStatus['key'] }} · {{ $sourceStatus['kind'] }}</p>
                                    </div>
                                    <span class="w-fit rounded-full px-2.5 py-1 text-xs font-bold {{ $sourceStatus['status_tone'] }}">{{ $sourceStatus['status_label'] }}</span>
                                </div>

                                <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt class="text-xs font-bold tracking-[0.1em] text-slate-500">REKAMAN DALAM SCOPE</dt>
                                        <dd class="mt-1 font-semibold text-slate-900">{{ number_format($sourceStatus['record_count']) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-bold tracking-[0.1em] text-slate-500">MODE</dt>
                                        <dd class="mt-1 font-semibold text-slate-900">{{ $sourceStatus['access_mode'] === 'read_only' ? 'Read-only' : 'Worker read-only' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-bold tracking-[0.1em] text-slate-500">KONFIGURASI</dt>
                                        <dd class="mt-1 font-semibold {{ $sourceStatus['readiness']['configured'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $sourceStatus['readiness']['configured'] ? 'Lengkap' : 'Belum lengkap' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-bold tracking-[0.1em] text-slate-500">RUN TERAKHIR</dt>
                                        <dd class="mt-1 font-semibold text-slate-900">{{ $latestRun?->finished_at_label !== '—' ? $latestRun?->finished_at_label : ($latestRun?->started_at_label ?? 'Belum ada') }}</dd>
                                    </div>
                                </dl>

                                <p class="mt-4 rounded-xl bg-white px-3 py-2 text-xs leading-5 text-slate-600 ring-1 ring-inset ring-slate-200">{{ $sourceStatus['readiness']['message'] }}</p>

                                @if ($latestRun !== null)
                                    <div class="mt-3 rounded-xl border border-slate-200 bg-white px-3 py-3 text-xs text-slate-600">
                                        <div class="grid gap-2 sm:grid-cols-3">
                                            <p><span class="font-semibold text-slate-800">Dibaca:</span> {{ number_format($latestRun->read_count) }}</p>
                                            <p><span class="font-semibold text-slate-800">Di-upsert:</span> {{ number_format($latestRun->upsert_count) }}</p>
                                            <p><span class="font-semibold text-slate-800">Error:</span> {{ number_format($latestRun->error_count) }}</p>
                                        </div>
                                        @if ($latestRun->error_summary)
                                            <p class="mt-2 border-t border-slate-100 pt-2 text-rose-700">{{ $latestRun->error_summary }}</p>
                                        @endif
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-bold tracking-[0.16em] text-indigo-600">SALINAN SUMBER</p>
                            <h2 class="mt-2 font-semibold text-slate-950">Rekaman staging</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ $records->total() }} rekaman sesuai filter. Payload hanya ditampilkan sebagai preview aman dan tidak dapat diubah.</p>
                        </div>
                        <span class="w-fit rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600">Maks. {{ $records->perPage() }} per halaman</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-bold tracking-[0.1em] text-slate-500">
                                <tr>
                                    <th scope="col" class="px-5 py-4">SUMBER</th>
                                    <th scope="col" class="px-5 py-4">TIPE / KUNCI</th>
                                    <th scope="col" class="px-5 py-4">PERUSAHAAN / CABANG</th>
                                    <th scope="col" class="px-5 py-4">WAKTU SUMBER</th>
                                    <th scope="col" class="px-5 py-4">TERSINKRON</th>
                                    <th scope="col" class="px-5 py-4">PAYLOAD</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white text-slate-700">
                                @forelse ($records as $record)
                                    <tr class="align-top hover:bg-slate-50/80">
                                        <td class="px-5 py-4">
                                            <p class="font-semibold text-slate-900">{{ $record->data_source_name }}</p>
                                            <p class="mt-1 font-mono text-xs text-slate-500">{{ $record->data_source_key }}</p>
                                        </td>
                                        <td class="px-5 py-4">
                                            <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">{{ $record->dataset_label }}</span>
                                            <p class="mt-2 max-w-56 break-all font-mono text-xs font-semibold text-slate-700">{{ $record->external_key }}</p>
                                        </td>
                                        <td class="px-5 py-4">
                                            <p class="font-semibold text-slate-900">{{ $record->legal_entity_code ?? '—' }}</p>
                                            <p class="mt-1 text-xs text-slate-500">{{ $record->branch_label }}</p>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-xs text-slate-600">
                                            <p>{{ $record->occurred_at_label }}</p>
                                            <p class="mt-1 text-slate-400">Update sumber: {{ $record->external_updated_at_label }}</p>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-xs text-slate-600">
                                            <p>{{ $record->synced_at_label }}</p>
                                            <p class="mt-1 font-mono text-[11px] text-slate-400">{{ $record->content_hash }}</p>
                                        </td>
                                        <td class="min-w-80 px-5 py-4">
                                            <details>
                                                <summary class="cursor-pointer text-sm font-semibold text-indigo-700 hover:text-indigo-900">Lihat preview</summary>
                                                <pre class="mt-3 max-h-56 overflow-auto whitespace-pre-wrap break-words rounded-xl bg-slate-950 p-3 text-xs leading-5 text-slate-100">{{ $record->payload_preview }}</pre>
                                            </details>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-5 py-12 text-center text-sm text-slate-500">Belum ada rekaman staging sesuai scope dan filter ini. Periksa filter atau minta operator integrasi menarik data ERP atau SQL Server.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($records->hasPages())
                        <div class="border-t border-slate-200 px-5 py-4">{{ $records->links() }}</div>
                    @endif
                </section>
            </main>
        </div>
    </body>
</html>
