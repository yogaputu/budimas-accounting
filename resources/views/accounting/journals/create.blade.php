<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0f172a">
        <title>Buat Jurnal Manual · Budimas Accounting</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-5 py-8 sm:px-8 lg:px-10">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-7 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <a href="{{ route('accounting.journals.index', ['legal_entity_id' => $selectedLegalEntity->id]) }}" class="text-xs font-bold tracking-[0.18em] text-indigo-600 transition hover:text-indigo-800">← KEMBALI KE JURNAL UMUM</a>
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950">Buat Jurnal Manual</h1>
                    <p class="mt-2 text-sm text-slate-600">Nomor jurnal dibuat otomatis per perusahaan. Jurnal disimpan sebagai draft sebelum diposting.</p>
                </div>
                <span class="inline-flex w-fit rounded-full bg-amber-100 px-3 py-1.5 text-xs font-bold text-amber-800">Draft baru</span>
            </header>

            <main class="py-8">
                @if ($errors->any())
                    <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
                        <p class="font-bold">Jurnal belum dapat disimpan.</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('accounting.journals.store') }}" id="journal-form" class="space-y-6">
                    @csrf

                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <div class="flex flex-col gap-1 border-b border-slate-100 pb-5">
                            <p class="text-xs font-bold tracking-[0.16em] text-indigo-600">01 · INFORMASI JURNAL</p>
                            <h2 class="text-lg font-bold text-slate-950">Scope dan tanggal transaksi</h2>
                        </div>

                        <div class="mt-5 grid gap-5 lg:grid-cols-4">
                            <label class="block lg:col-span-2">
                                <span class="text-xs font-bold tracking-[0.12em] text-slate-500">PERUSAHAAN</span>
                                <select name="legal_entity_id" id="legal-entity-id" class="mt-2 w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                                    @foreach ($legalEntities as $legalEntity)
                                        <option value="{{ $legalEntity->id }}" @selected((int) old('legal_entity_id', $selectedLegalEntity->id) === $legalEntity->id)>{{ $legalEntity->code }} — {{ $legalEntity->name }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="block">
                                <span class="text-xs font-bold tracking-[0.12em] text-slate-500">CABANG <span class="font-medium normal-case tracking-normal">(opsional)</span></span>
                                <select name="branch_id" id="entry-branch-id" class="mt-2 w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Semua / tanpa cabang</option>
                                </select>
                            </label>

                            <label class="block">
                                <span class="text-xs font-bold tracking-[0.12em] text-slate-500">TANGGAL JURNAL</span>
                                <input type="date" name="entry_date" id="entry-date" value="{{ old('entry_date', now()->toDateString()) }}" class="mt-2 w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                            </label>
                        </div>

                        <div id="period-message" class="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-800"></div>

                        <label class="mt-5 block">
                            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">KETERANGAN <span class="font-medium normal-case tracking-normal">(opsional)</span></span>
                            <textarea name="description" rows="3" maxlength="255" class="mt-2 w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:ring-indigo-500" placeholder="Contoh: Penyesuaian biaya administrasi bank bulan September">{{ old('description') }}</textarea>
                        </label>
                    </section>

                    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-4 border-b border-slate-200 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div>
                                <p class="text-xs font-bold tracking-[0.16em] text-indigo-600">02 · BARIS JURNAL</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">Debit dan kredit</h2>
                                <p class="mt-1 text-sm text-slate-600">Setiap baris hanya boleh berisi debit atau kredit. Total wajib sama.</p>
                            </div>
                            <button type="button" id="add-line" class="inline-flex w-fit items-center justify-center rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-semibold text-indigo-700 transition hover:border-indigo-300 hover:bg-indigo-100">+ Tambah baris</button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-[980px] w-full divide-y divide-slate-200 text-left text-sm">
                                <thead class="bg-slate-50 text-xs font-bold tracking-[0.1em] text-slate-500">
                                    <tr>
                                        <th scope="col" class="w-12 px-5 py-3.5 text-center">#</th>
                                        <th scope="col" class="min-w-72 px-5 py-3.5">AKUN</th>
                                        <th scope="col" class="min-w-48 px-5 py-3.5">CABANG</th>
                                        <th scope="col" class="min-w-56 px-5 py-3.5">MEMO</th>
                                        <th scope="col" class="min-w-36 px-5 py-3.5 text-right">DEBIT</th>
                                        <th scope="col" class="min-w-36 px-5 py-3.5 text-right">KREDIT</th>
                                        <th scope="col" class="w-20 px-5 py-3.5"><span class="sr-only">Hapus</span></th>
                                    </tr>
                                </thead>
                                <tbody id="journal-lines" class="divide-y divide-slate-100 bg-white"></tbody>
                                <tfoot class="border-t-2 border-slate-200 bg-slate-50 font-bold text-slate-900">
                                    <tr>
                                        <td colspan="4" class="px-5 py-4 text-right text-sm">TOTAL</td>
                                        <td id="total-debit" class="px-5 py-4 text-right tabular-nums">Rp 0,00</td>
                                        <td id="total-credit" class="px-5 py-4 text-right tabular-nums">Rp 0,00</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div id="balance-message" class="mx-5 mb-5 rounded-2xl border px-4 py-3 text-sm sm:mx-6"></div>
                    </section>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                        <a href="{{ route('accounting.journals.index', ['legal_entity_id' => $selectedLegalEntity->id]) }}" class="inline-flex justify-center rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-400">Batal</a>
                        <button type="submit" id="save-draft" class="inline-flex justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">Simpan sebagai Draft</button>
                    </div>
                </form>
            </main>
        </div>

        <script>
            const journalFormOptions = {{ Illuminate\Support\Js::from($journalFormOptions) }};
            const initialLines = {{ Illuminate\Support\Js::from(old('lines', [
                ['chart_of_account_id' => '', 'branch_id' => '', 'memo' => '', 'debit' => '', 'credit' => ''],
                ['chart_of_account_id' => '', 'branch_id' => '', 'memo' => '', 'debit' => '', 'credit' => ''],
            ])) }};
            const initialBranchId = {{ Illuminate\Support\Js::from(old('branch_id', '')) }};

            const entitySelect = document.getElementById('legal-entity-id');
            const entryBranchSelect = document.getElementById('entry-branch-id');
            const entryDateInput = document.getElementById('entry-date');
            const lineContainer = document.getElementById('journal-lines');
            const addLineButton = document.getElementById('add-line');
            const saveDraftButton = document.getElementById('save-draft');
            let lineSequence = 0;

            const maximumAmountCents = 99999999999999999999n;

            function amountCents(value) {
                if (value === '') {
                    return 0n;
                }

                const parts = /^(\d+)(?:\.(\d{1,2}))?$/.exec(value);

                if (parts === null) {
                    return null;
                }

                const cents = BigInt(parts[1]) * 100n + BigInt((parts[2] || '').padEnd(2, '0'));

                return cents <= maximumAmountCents ? cents : null;
            }

            function formatCents(cents) {
                const whole = (cents / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                const fraction = (cents % 100n).toString().padStart(2, '0');

                return `Rp ${whole},${fraction}`;
            }

            function selectedEntityId() {
                return entitySelect.value;
            }

            function createOption(value, label, selected = false) {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                option.selected = String(value) === String(selected);

                return option;
            }

            function replaceOptions(select, options, placeholder, selected = '') {
                select.replaceChildren(createOption('', placeholder, selected === ''));

                options.forEach((option) => {
                    select.append(createOption(option.id, option.label, selected));
                });
            }

            function optionsFor(key) {
                return journalFormOptions[key][selectedEntityId()] || [];
            }

            function inputElement(type, name, value, className) {
                const input = document.createElement('input');
                input.type = type;
                input.name = name;
                input.value = value || '';
                input.className = className;

                return input;
            }

            function addLine(line = {}) {
                const rowIndex = lineSequence++;
                const row = document.createElement('tr');
                row.className = 'journal-line';
                row.dataset.lineIndex = String(rowIndex);

                const numberCell = document.createElement('td');
                numberCell.className = 'line-number px-5 py-3 text-center text-sm font-semibold text-slate-500';

                const accountCell = document.createElement('td');
                accountCell.className = 'px-5 py-3';
                const accountSelect = document.createElement('select');
                accountSelect.name = `lines[${rowIndex}][chart_of_account_id]`;
                accountSelect.required = true;
                accountSelect.className = 'line-account w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
                replaceOptions(accountSelect, optionsFor('accountsByEntity'), 'Pilih akun', line.chart_of_account_id || '');
                accountCell.append(accountSelect);

                const branchCell = document.createElement('td');
                branchCell.className = 'px-5 py-3';
                const branchSelect = document.createElement('select');
                branchSelect.name = `lines[${rowIndex}][branch_id]`;
                branchSelect.className = 'line-branch w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';
                replaceOptions(branchSelect, optionsFor('branchesByEntity'), 'Tanpa cabang', line.branch_id || '');
                branchCell.append(branchSelect);

                const memoCell = document.createElement('td');
                memoCell.className = 'px-5 py-3';
                const memo = inputElement('text', `lines[${rowIndex}][memo]`, line.memo, 'w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:ring-indigo-500');
                memo.maxLength = 255;
                memo.placeholder = 'Catatan baris';
                memoCell.append(memo);

                const debitCell = document.createElement('td');
                debitCell.className = 'px-5 py-3';
                const debit = inputElement('number', `lines[${rowIndex}][debit]`, line.debit, 'line-debit w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-right text-sm tabular-nums text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:ring-indigo-500');
                debit.min = '0';
                debit.step = '0.01';
                debit.inputMode = 'decimal';
                debit.placeholder = '0,00';
                debit.addEventListener('input', updateTotals);
                debitCell.append(debit);

                const creditCell = document.createElement('td');
                creditCell.className = 'px-5 py-3';
                const credit = inputElement('number', `lines[${rowIndex}][credit]`, line.credit, 'line-credit w-full rounded-xl border-slate-300 bg-white px-3 py-2.5 text-right text-sm tabular-nums text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:ring-indigo-500');
                credit.min = '0';
                credit.step = '0.01';
                credit.inputMode = 'decimal';
                credit.placeholder = '0,00';
                credit.addEventListener('input', updateTotals);
                creditCell.append(credit);

                const actionCell = document.createElement('td');
                actionCell.className = 'px-5 py-3 text-center';
                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.textContent = 'Hapus';
                removeButton.className = 'line-remove rounded-lg px-2.5 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:text-slate-400';
                removeButton.addEventListener('click', () => {
                    if (lineContainer.children.length <= 2) {
                        return;
                    }

                    row.remove();
                    refreshLineNumbers();
                    updateTotals();
                });
                actionCell.append(removeButton);

                row.append(numberCell, accountCell, branchCell, memoCell, debitCell, creditCell, actionCell);
                lineContainer.append(row);
                refreshLineNumbers();
                updateTotals();
            }

            function refreshLineNumbers() {
                Array.from(lineContainer.children).forEach((row, index) => {
                    row.querySelector('.line-number').textContent = String(index + 1);
                    row.querySelector('.line-remove').disabled = lineContainer.children.length <= 2;
                });
            }

            function updateTotals() {
                let debit = 0n;
                let credit = 0n;
                let validAmounts = true;

                lineContainer.querySelectorAll('.line-debit').forEach((input) => {
                    const cents = amountCents(input.value);
                    validAmounts = validAmounts && cents !== null && input.validity.valid;
                    debit += cents ?? 0n;
                });
                lineContainer.querySelectorAll('.line-credit').forEach((input) => {
                    const cents = amountCents(input.value);
                    validAmounts = validAmounts && cents !== null && input.validity.valid;
                    credit += cents ?? 0n;
                });

                document.getElementById('total-debit').textContent = formatCents(debit);
                document.getElementById('total-credit').textContent = formatCents(credit);

                const balanced = validAmounts && debit === credit && debit > 0n && debit <= maximumAmountCents;
                const balanceMessage = document.getElementById('balance-message');
                balanceMessage.className = balanced
                    ? 'mx-5 mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 sm:mx-6'
                    : 'mx-5 mb-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800 sm:mx-6';
                balanceMessage.textContent = balanced
                    ? 'Debit dan kredit sudah seimbang. Jurnal dapat disimpan sebagai draft.'
                    : 'Lengkapi debit dan kredit hingga totalnya sama. Validasi akhir tetap dilakukan oleh server.';
                saveDraftButton.disabled = !balanced || !hasOpenPeriod();
            }

            function hasOpenPeriod() {
                const entryDate = entryDateInput.value;
                const periods = optionsFor('openPeriodsByEntity');

                return periods.some((period) => entryDate >= period.starts_on && entryDate <= period.ends_on);
            }

            function updatePeriodMessage() {
                const periodMessage = document.getElementById('period-message');
                const entryDate = entryDateInput.value;
                const matchingPeriod = optionsFor('openPeriodsByEntity').find((period) => entryDate >= period.starts_on && entryDate <= period.ends_on);

                if (matchingPeriod) {
                    periodMessage.className = 'mt-5 rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-800';
                    periodMessage.textContent = `Tanggal ini akan masuk ke periode terbuka ${matchingPeriod.code} (${matchingPeriod.starts_on} s.d. ${matchingPeriod.ends_on}).`;
                } else {
                    periodMessage.className = 'mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800';
                    periodMessage.textContent = 'Tidak ada periode Accounting terbuka untuk tanggal dan perusahaan ini. Jurnal tidak dapat disimpan.';
                }

                updateTotals();
            }

            function refreshEntityOptions() {
                replaceOptions(entryBranchSelect, optionsFor('branchesByEntity'), 'Semua / tanpa cabang', initialBranchId);

                lineContainer.querySelectorAll('.journal-line').forEach((row) => {
                    const accountSelect = row.querySelector('.line-account');
                    const branchSelect = row.querySelector('.line-branch');
                    const selectedAccount = accountSelect.value;
                    const selectedBranch = branchSelect.value;

                    replaceOptions(accountSelect, optionsFor('accountsByEntity'), 'Pilih akun', selectedAccount);
                    replaceOptions(branchSelect, optionsFor('branchesByEntity'), 'Tanpa cabang', selectedBranch);
                });

                updatePeriodMessage();
            }

            initialLines.forEach((line) => addLine(line));
            replaceOptions(entryBranchSelect, optionsFor('branchesByEntity'), 'Semua / tanpa cabang', initialBranchId);
            entitySelect.addEventListener('change', refreshEntityOptions);
            entryDateInput.addEventListener('change', updatePeriodMessage);
            addLineButton.addEventListener('click', () => addLine());
            updatePeriodMessage();
        </script>
    </body>
</html>
