@props(['checklist', 'snapshot'])
<form method="POST" action="{{ route('recommendations.alternatives', $snapshot['id']) }}" data-ingredient-checklist data-save-url="{{ route('recommendations.checklist', $snapshot['id']) }}">
    @csrf
    <p class="mt-3 text-sm leading-6 text-muted">Centang hanya bahan yang benar-benar kamu punya. Kecukupan takaran tetap perlu diperiksa karena jumlah stok belum dicatat.</p>
    @foreach (['Bahan wajib' => false, 'Bahan opsional' => true] as $heading => $optional)
        <h3 class="mt-6 font-bold">{{ $heading }}</h3>
        <ul class="mt-2 divide-y divide-line">
        @forelse (array_filter($checklist['items'], fn ($item) => $item['optional'] === $optional) as $ingredient)
            <li><label class="flex cursor-pointer items-start gap-3 py-4">
                <input type="checkbox" name="confirmed[]" value="{{ $ingredient['key'] }}" @checked($ingredient['available'])
                    data-stock-item data-optional="{{ $optional ? 'true' : 'false' }}" data-required="{{ $optional ? 'false' : 'true' }}" data-name="{{ $ingredient['name'] }}"
                    class="mt-1 size-5 shrink-0 rounded accent-primary">
                <span class="min-w-0 flex-1"><span class="block font-semibold break-words">{{ $ingredient['name'] }}</span>
                    <span class="mt-1 block text-sm break-words text-muted">{{ $ingredient['quantity'] }}</span>
                    <span data-stock-label class="mt-2 block text-xs font-semibold {{ $ingredient['available'] ? 'text-primary' : 'text-ink' }}">{{ $ingredient['available'] ? 'Tersedia' : 'Belum tersedia' }}{{ $optional ? ' · Opsional' : '' }}</span>
                </span>
            </label></li>
        @empty<li class="py-3 text-sm text-muted">Tidak ada.</li>@endforelse
        </ul>
    @endforeach
    @if($checklist['other'])
        <h3 class="mt-6 font-bold">Bahan lain yang kamu punya</h3>
        <p class="mt-2 text-sm text-muted">Bahan ini tidak dipakai dalam resep ini, tetapi ikut dipertimbangkan saat mencari menu lain.</p>
        <ul class="mt-2 divide-y divide-line">@foreach($checklist['other'] as $key)<li>
            <label class="flex cursor-pointer items-start gap-3 py-3"><input type="checkbox" name="confirmed[]" value="{{ $key }}" @checked(in_array($key, $checklist['confirmed'], true)) data-stock-item data-optional="false" data-required="false" data-name="{{ $checklist['catalog'][$key] }}" class="mt-1 size-5 shrink-0 accent-primary">
            <span>{{ $checklist['catalog'][$key] }}<span data-stock-label class="mt-1 block text-xs text-primary">{{ in_array($key, $checklist['confirmed'], true) ? 'Tersedia' : 'Belum tersedia' }}</span></span></label>
        </li>@endforeach</ul>
    @endif
    <div class="mt-6 rounded-2xl bg-cream p-4" aria-live="polite">
        <p>Bahan tersedia: <strong data-stock-count>{{ count($checklist['confirmed']) }}</strong></p>
        <p>Bahan tambahan wajib: <strong data-missing-count>{{ $checklist['match']['missing_ingredients_count'] }}</strong></p>
        <p data-stock-status class="mt-3 font-semibold">{{ $checklist['match']['ready'] ? 'Semua bahan sudah tersedia. Yuk, mulai masak!' : 'Kamu masih membutuhkan '.$checklist['match']['missing_ingredients_count'].' bahan tambahan.' }}</p>
        <div data-missing-section @if(!$checklist['match']['missing']) hidden @endif><p class="mt-3">Bahan yang belum tersedia:</p><ul data-missing-list class="mt-2 list-inside list-disc">@foreach($checklist['match']['missing'] as $item)<li>{{ $item['name'] }}</li>@endforeach</ul></div>
    </div>
    <p data-save-status role="status" class="mt-3 text-sm text-muted"></p>
    @if ($errors->any())<p role="alert" class="mt-3 text-sm">{{ $errors->first() }}</p>@endif
    <x-button type="submit" class="mt-5 w-full" data-find-available :disabled="!$checklist['match']['missing'] || !$checklist['confirmed']">Cari Menu dari Bahan yang Ada</x-button>
    <p data-alternative-loading role="status" class="mt-3 text-sm text-primary" hidden>Lagi cari masakan dari bahan yang kamu punya...</p>
    <noscript><p class="mt-3 text-sm">Aktifkan JavaScript untuk memperbarui dan menyimpan checklist saat mencentang bahan.</p></noscript>
</form>
