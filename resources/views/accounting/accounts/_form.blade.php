@php
    $selectedEntityId = (int) old('legal_entity_id', $account->legal_entity_id ?: $selectedLegalEntityId);
    $isPostable = (bool) old('is_postable', $account->exists ? $account->is_postable : true);
    $isActive = (bool) old('is_active', $account->exists ? $account->is_active : true);
@endphp

@if ($errors->any())
    <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
        <p class="font-semibold">Periksa kembali data akun.</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ $formAction }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
    @csrf
    @if ($formMethod !== 'POST')
        @method($formMethod)
    @endif

    <div class="grid gap-5 md:grid-cols-2">
        <label class="block">
            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">PERUSAHAAN</span>
            @if ($account->exists)
                <input type="hidden" name="legal_entity_id" value="{{ $account->legal_entity_id }}">
                <select disabled class="mt-2 block w-full rounded-xl border-slate-300 bg-slate-100 text-sm text-slate-600 shadow-sm">
                    @foreach ($legalEntities as $legalEntity)
                        <option @selected($legalEntity->id === $account->legal_entity_id)>{{ $legalEntity->code }} · {{ $legalEntity->name }}</option>
                    @endforeach
                </select>
                <span class="mt-2 block text-xs leading-5 text-slate-500">Perusahaan akun dikunci agar struktur dan histori jurnal tidak berpindah scope.</span>
            @else
                <select name="legal_entity_id" data-account-entity class="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('legal_entity_id') border-rose-400 @enderror" required>
                    <option value="">Pilih perusahaan</option>
                    @foreach ($legalEntities as $legalEntity)
                        <option value="{{ $legalEntity->id }}" @selected($selectedEntityId === $legalEntity->id)>{{ $legalEntity->code }} · {{ $legalEntity->name }}</option>
                    @endforeach
                </select>
            @endif
            @error('legal_entity_id')<span class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
        </label>

        <label class="block">
            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">KODE AKUN</span>
            <input type="text" name="code" value="{{ old('code', $account->code) }}" maxlength="32" placeholder="Contoh: 1105" autocomplete="off" class="mt-2 block w-full rounded-xl border-slate-300 font-mono text-sm shadow-sm placeholder:font-sans placeholder:text-slate-400 focus:border-indigo-500 focus:ring-indigo-500 @error('code') border-rose-400 @enderror" required>
            @error('code')<span class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
        </label>

        <label class="block md:col-span-2">
            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">NAMA AKUN</span>
            <input type="text" name="name" value="{{ old('name', $account->name) }}" maxlength="255" placeholder="Contoh: Piutang Usaha" class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:ring-indigo-500 @error('name') border-rose-400 @enderror" required>
            @error('name')<span class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
        </label>

        <label class="block">
            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">KATEGORI</span>
            <select name="category" data-account-category class="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('category') border-rose-400 @enderror" required>
                <option value="">Pilih kategori</option>
                @foreach ($categories as $key => $label)
                    <option value="{{ $key }}" @selected(old('category', $account->category) === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @error('category')<span class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
        </label>

        <label class="block">
            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">SALDO NORMAL</span>
            <select name="normal_balance" class="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('normal_balance') border-rose-400 @enderror" required>
                @foreach ($normalBalances as $key => $label)
                    <option value="{{ $key }}" @selected(old('normal_balance', $account->normal_balance ?: 'debit') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @error('normal_balance')<span class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
        </label>

        <label class="block md:col-span-2">
            <span class="text-xs font-bold tracking-[0.12em] text-slate-500">INDUK AKUN <span class="normal-case tracking-normal text-slate-400">(opsional)</span></span>
            <select name="parent_id" data-account-parent class="mt-2 block w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('parent_id') border-rose-400 @enderror">
                <option value="">Tidak ada · akun tingkat utama</option>
                @foreach ($parentAccounts as $parentAccount)
                    <option value="{{ $parentAccount->id }}" data-entity-id="{{ $parentAccount->legal_entity_id }}" data-category="{{ $parentAccount->category }}" @selected((int) old('parent_id', $account->parent_id) === $parentAccount->id)>
                        {{ $parentAccount->legalEntity?->code }} · {{ $parentAccount->code }} · {{ $parentAccount->name }}
                    </option>
                @endforeach
            </select>
            <span class="mt-2 block text-xs leading-5 text-slate-500">Hanya akun kelompok yang sejenis dan berasal dari perusahaan yang sama dapat menjadi induk.</span>
            @error('parent_id')<span class="mt-2 block text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
        </label>
    </div>

    <div class="mt-6 grid gap-3 border-t border-slate-200 pt-6 sm:grid-cols-2">
        <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 p-4 transition hover:border-indigo-200 hover:bg-indigo-50/30">
            <input type="checkbox" name="is_postable" value="1" @checked($isPostable) class="mt-0.5 size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
            <span>
                <span class="block text-sm font-semibold text-slate-900">Akun posting</span>
                <span class="mt-1 block text-xs leading-5 text-slate-500">Dapat dipilih saat membuat garis jurnal. Nonaktifkan pilihan ini untuk akun kelompok/header.</span>
            </span>
        </label>
        <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 p-4 transition hover:border-indigo-200 hover:bg-indigo-50/30">
            <input type="checkbox" name="is_active" value="1" @checked($isActive) class="mt-0.5 size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
            <span>
                <span class="block text-sm font-semibold text-slate-900">Akun aktif</span>
                <span class="mt-1 block text-xs leading-5 text-slate-500">Akun nonaktif tetap tersimpan untuk histori tetapi tidak digunakan untuk transaksi baru.</span>
            </span>
        </label>
    </div>

    <div class="mt-8 flex flex-wrap justify-end gap-3">
        <a href="{{ route('accounting.accounts.index', ['entity' => $selectedEntityId]) }}" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-400">Batal</a>
        <button type="submit" class="inline-flex items-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">{{ $submitLabel }}</button>
    </div>
</form>

<script>
    (() => {
        const entity = document.querySelector('[data-account-entity]');
        const category = document.querySelector('[data-account-category]');
        const parent = document.querySelector('[data-account-parent]');

        if (!parent) {
            return;
        }

        const syncParentOptions = () => {
            const entityId = entity ? entity.value : '{{ $account->legal_entity_id }}';
            const categoryKey = category ? category.value : '';

            [...parent.options].forEach((option) => {
                if (option.value === '') {
                    return;
                }

                const shouldHide = option.dataset.entityId !== entityId || (categoryKey !== '' && option.dataset.category !== categoryKey);
                option.hidden = shouldHide;

                if (shouldHide && option.selected) {
                    parent.value = '';
                }
            });
        };

        entity?.addEventListener('change', syncParentOptions);
        category?.addEventListener('change', syncParentOptions);
        syncParentOptions();
    })();
</script>
