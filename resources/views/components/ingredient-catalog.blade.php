@php($categoryLabels = ['Rempah dan Bumbu Aromatik' => 'Rempah', 'Minyak, Lemak, dan Cairan' => 'Minyak dan Cairan'])
<div class="mt-5">
    <p id="category-label" class="text-xs font-semibold text-muted">Kategori</p>
    <div class="mt-2 flex gap-2 overflow-x-auto pb-2 sm:flex-wrap" role="group" aria-labelledby="category-label">
        @foreach(['' => []] + config('ingredients') as $category => $items)
            <button type="button" data-catalog-category="{{ $category }}" aria-pressed="{{ $category === '' ? 'true' : 'false' }}" class="shrink-0 rounded-full border border-line px-3 py-2 text-xs font-semibold text-ink hover:bg-sage aria-pressed:border-primary aria-pressed:bg-sage aria-pressed:text-primary">{{ $category === '' ? 'Semua' : ($categoryLabels[$category] ?? $category) }}</button>
        @endforeach
    </div>
    <p id="catalog-status" role="status" class="mt-3 text-xs text-muted"></p>
    <div class="mt-3 max-h-56 overflow-y-auto pr-2" tabindex="0" aria-label="Daftar pilihan bahan">
        @foreach(config('ingredients') as $category => $items)
            <section data-catalog-group="{{ $category }}" class="mb-5 last:mb-0" aria-label="{{ $categoryLabels[$category] ?? $category }}">
                <h4 class="mb-3 text-sm font-bold">{{ $categoryLabels[$category] ?? $category }}</h4>
                <div class="flex flex-wrap gap-2">@foreach($items as $ingredient)
                    <button type="button" data-catalog-ingredient="{{ $ingredient }}" aria-pressed="false" class="rounded-full border border-line bg-white px-3 py-2 text-xs font-medium text-ink transition hover:border-primary hover:bg-sage aria-pressed:border-primary aria-pressed:bg-primary aria-pressed:text-white motion-reduce:transition-none">{{ $ingredient }}<span data-catalog-selected aria-hidden="true"> +</span></button>
                @endforeach</div>
            </section>
        @endforeach
        <p id="catalog-empty" hidden class="py-3 text-sm text-muted">Tidak ada pilihan yang cocok. Coba kategori lain, atau tekan Tambah / Enter untuk menambahkan bahan yang kamu ketik.</p>
    </div>
</div>
