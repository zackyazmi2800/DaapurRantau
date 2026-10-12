@extends('layouts.app')
@section('title', 'Riwayat Pencarian — Dapur Rantau')
@section('content')
<section class="page-container py-12 sm:py-16">
    <p class="eyebrow">PERNAH MASAK APA?</p><h1 class="section-title">Riwayat Pencarian</h1><x-visitor-notice />
    <p class="mt-3 text-sm leading-7 text-muted">Menampilkan 30 pencarian terakhir. Alergi tidak disimpan dalam riwayat; isi kembali bahan yang perlu dihindari saat mencari lagi.</p>
    @if ($searches->isEmpty())
        <div class="mt-10 rounded-3xl bg-cream p-8 text-center"><h2 class="text-2xl font-bold">Belum ada riwayat pencarian. Yuk, mulai dari bahan yang kamu punya!</h2><x-button href="{{ route('home') }}#cari-resep" class="mt-6">Cari Resep</x-button></div>
    @else
        <details class="mt-8 rounded-2xl border border-line p-5"><summary class="cursor-pointer font-semibold">Hapus Seluruh Riwayat</summary><form method="POST" action="{{ route('history.clear') }}" class="mt-4">@csrf @method('DELETE')<label class="flex items-start gap-3 text-sm"><input type="checkbox" name="confirm" value="1" required class="size-5 shrink-0 accent-primary">Saya yakin ingin menghapus seluruh riwayat pencarian saya. Tindakan ini tidak dapat dibatalkan.</label><x-button type="submit" variant="secondary" class="mt-4">Konfirmasi Hapus Seluruh Riwayat</x-button></form></details>
        @error('confirm')<p role="alert" class="mt-3 text-sm">{{ $message }}</p>@enderror
        <div class="mt-8 space-y-5">@foreach ($searches as $search)
            <article class="rounded-3xl border border-line p-6 shadow-soft"><time datetime="{{ $search->created_at->toIso8601String() }}" class="text-sm text-muted">{{ $search->created_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</time><h2 class="mt-3 text-lg font-bold">{{ $search->recipe_count }} rekomendasi · {{ ucfirst($search->preferences['preference']) }} · {{ $search->preferences['servings'] }} porsi</h2><p class="mt-2 text-sm text-primary">{{ ($search->preferences['mode'] ?? 'NORMAL') === 'STRICT_AVAILABLE_INGREDIENTS' ? 'Tanpa Bahan Tambahan' : 'Rekomendasi Normal' }}</p><ul class="mt-4 flex flex-wrap gap-2">@foreach ($search->ingredients as $ingredient)<li class="max-w-full rounded-full bg-sage px-3 py-2 text-sm break-words text-primary">{{ $ingredient }}</li>@endforeach</ul>
                <div class="mt-5 flex flex-wrap gap-3"><x-button href="{{ route('history.repeat', $search->id) }}">Cari Lagi</x-button>@if ($available[$search->id])<x-button variant="secondary" href="{{ route('recommendations.show', $search->snapshot_id) }}">Buka Hasil</x-button>@endif<form method="POST" action="{{ route('history.destroy', $search->id) }}">@csrf @method('DELETE')<x-button type="submit" variant="secondary">Hapus</x-button></form></div>
                @unless ($available[$search->id])<p class="mt-4 text-sm text-muted">Hasil lama sudah tidak tersedia. Cari lagi untuk mendapatkan rekomendasi baru.</p>@endunless
            </article>
        @endforeach</div>
    @endif
</section>
@endsection
