@php($ingredientLimit = old('mode') === \App\Support\RecipeMode::STRICT ? 105 : 15)
<form id="ingredient-form" data-ingredient-limit="{{ $ingredientLimit }}" data-ingredient-aliases="{{ json_encode(app(\App\Services\RecipeMatchingService::class)->aliases()) }}" method="POST" action="{{ route('recommendations.store') }}" novalidate>
    @csrf
    @if(old('mode') === \App\Support\RecipeMode::STRICT)<input type="hidden" name="mode" value="STRICT_AVAILABLE_INGREDIENTS">@endif
    @if ($errors->any())
        <div role="alert" tabindex="-1" id="validation-errors" class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-bold">Periksa kembali inputmu:</p>
            <ul class="mt-2 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <section aria-labelledby="available-title">
    <div class="mb-3 flex items-center justify-between gap-3"><h3 id="available-title" class="text-lg font-bold">Bahan Tersedia</h3><span id="ingredient-count" class="text-xs text-muted" aria-live="polite">Bahan dipilih: 0 / {{ $ingredientLimit }}</span></div>
    <p class="mb-4 text-sm text-muted">Masukkan atau pilih bahan yang ada di dapurmu.</p>
    <label for="ingredient-input" class="sr-only">Cari atau ketik bahan</label>
    <div class="flex gap-2 rounded-xl border border-line bg-cream/40 p-2 focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/15">
        <input id="ingredient-input" type="text" autocomplete="off" placeholder="Cari atau ketik bahan yang kamu punya..." aria-describedby="ingredient-help ingredient-feedback" class="min-w-0 flex-1 rounded-lg bg-transparent px-2 text-sm outline-none sm:px-3">
        <button id="add-ingredient" type="button" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-sage px-3 py-3 text-sm font-bold text-primary hover:bg-primary/15" aria-label="Tambahkan bahan"><x-icon name="plus" class="size-4" /><span>Tambah</span></button>
    </div>
    <p id="ingredient-help" class="mt-2.5 text-xs leading-5 text-muted">Pisahkan beberapa bahan dengan koma, lalu tekan Enter atau Tambah. Maksimal {{ $ingredientLimit }} bahan, masing-masing 60 karakter.</p>
    <div id="ingredient-values">
        @foreach (is_array(old('ingredients')) ? old('ingredients') : [] as $ingredient)
            @if (is_string($ingredient))<input type="hidden" name="ingredients[]" value="{{ $ingredient }}">@endif
        @endforeach
    </div>
    <p id="ingredient-feedback" role="status" aria-live="polite" class="mt-2 text-xs text-primary"></p>
    <x-ingredient-catalog />
    <div class="mt-5 border-t border-line pt-4">
        <h4 class="text-sm font-bold">Bahan yang kamu pilih (<span id="selected-count">0</span>)</h4>
        <div id="ingredient-chips" aria-label="Bahan yang dipilih" class="mt-3 flex min-h-10 flex-wrap gap-2"></div>
        <p id="ingredient-empty" class="mt-2 text-sm text-muted">Belum ada bahan. Yuk, tambahkan bahan pertamamu.</p>
    </div>
    </section>
    <fieldset class="mt-6 border-t border-line pt-5">
        <legend class="px-1 text-sm font-bold">Pilihan tambahan <span class="font-normal text-muted">(opsional)</span></legend>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div><label for="servings" class="mb-2 block text-sm font-medium">Jumlah porsi</label><input id="servings" name="servings" type="number" min="1" max="10" step="1" value="{{ is_scalar(old('servings')) ? old('servings') : '' }}" placeholder="1–10 porsi" class="w-full rounded-xl border border-line bg-cream/30 px-4 py-3 text-sm"></div>
            <div><label for="preference" class="mb-2 block text-sm font-medium">Preferensi masakan</label><select id="preference" name="preference" class="w-full rounded-xl border border-line bg-cream/30 px-4 py-3 text-sm">@foreach (['bebas' => 'Bebas', 'hemat' => 'Hemat', 'cepat' => 'Cepat', 'pedas' => 'Pedas'] as $value => $label)<option value="{{ $value }}" @selected(old('preference', 'bebas') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="sm:col-span-2"><label for="allergies" class="mb-2 block text-sm font-medium">Bahan yang dihindari karena alergi</label><textarea id="allergies" name="allergies" rows="2" maxlength="600" placeholder="Misalnya: kacang, udang" aria-describedby="allergies-help" class="w-full rounded-xl border border-line bg-cream/30 px-4 py-3 text-sm">{{ is_string(old('allergies')) ? old('allergies') : '' }}</textarea><p id="allergies-help" class="mt-2 text-xs text-muted">Pisahkan dengan koma. Maksimal 600 karakter.</p></div>
        </div>
    </fieldset>
    <x-button id="search-recipes" type="submit" disabled class="mt-6 w-full disabled:cursor-not-allowed"><x-icon name="sparkles" class="size-4" /><span data-button-label>Cari Ide Masakan</span><span data-spinner hidden class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white motion-reduce:animate-none"></span></x-button>
    <p class="mt-4 text-center text-xs leading-5 text-muted">Bahanmu digunakan untuk mencari resep dengan Gemini. Hasil AI tetap perlu diperiksa sebelum memasak.</p>
    <p id="search-feedback" role="status" aria-live="polite" class="mt-2 text-center text-sm font-medium text-primary"></p>
    <noscript><p class="mt-4 text-sm text-muted">Aktifkan JavaScript untuk menambah dan mengirim bahan.</p></noscript>
</form>
