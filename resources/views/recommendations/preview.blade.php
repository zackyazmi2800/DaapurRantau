@extends('layouts.app')
@section('title', 'Ide masakan untukmu — Dapur Rantau')
@section('content')
@php($strict = \App\Support\RecipeMode::strict($data))
<section class="border-b border-line bg-cream/70 py-12 sm:py-16" aria-labelledby="result-title">
    <div class="page-container">
        <p class="eyebrow">DARI BAHANMU, JADI MENU HARI INI</p>
        <h1 id="result-title" class="section-title max-w-2xl sm:text-5xl">{{ $strict ? 'Masak dari Bahan yang Kamu Punya' : 'Ini Dia Ide Masakan Buat Kamu!' }}</h1>
        <p class="mt-5 max-w-xl leading-7 text-muted">{{ $strict ? 'Ini ide masakan yang bisa kamu buat tanpa perlu menambahkan bahan wajib lainnya.' : 'Kami menemukan beberapa ide masakan berdasarkan bahan yang kamu punya.' }}</p>
        <div class="mt-8 flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
            <div><p class="mb-3 text-xs font-bold tracking-wider text-muted">BAHAN PILIHANMU</p><ul class="flex flex-wrap gap-2">@foreach ($data['ingredients'] as $ingredient)<li class="max-w-full rounded-full border border-primary/15 bg-white px-4 py-2 text-sm font-semibold break-words text-primary">{{ $ingredient }}</li>@endforeach</ul></div>
            <x-button href="{{ route('recommendations.edit', $snapshot['id']) }}" variant="secondary" class="shrink-0">Ubah Bahan</x-button>
        </div>
    </div>
</section>
<section class="page-container py-12 sm:py-16" aria-label="Daftar rekomendasi masakan">
    @if ($snapshot['recipes'])
        <div class="mb-8 flex flex-wrap items-center justify-between gap-3"><h2 class="text-xl font-bold">{{ count($snapshot['recipes']) }} ide untuk dapurmu</h2><p class="text-sm text-muted">{{ $strict ? 'Tanpa Bahan Tambahan' : 'Diurutkan berdasarkan kebutuhan bahan tambahan' }}</p></div>
        <div class="grid items-stretch gap-6 md:grid-cols-2 lg:grid-cols-3">@foreach ($snapshot['recipes'] as $recipe)<x-recommendation-card :recipe="$recipe" :search="$snapshot['id']" />@endforeach</div>
    @else
        <div class="rounded-3xl border border-line bg-sage/40 px-6 py-14 text-center"><x-icon name="bowl" class="mx-auto size-12 text-primary" /><h2 class="mx-auto mt-6 max-w-lg text-2xl font-bold">{{ $strict ? 'Belum ada masakan yang bisa dibuat hanya dari bahan ini. Coba tambahkan bahan lain, yuk!' : 'Wah, belum ada resep yang cocok. Coba tambahkan bahan lain, yuk!' }}</h2><x-button href="{{ route('recommendations.edit', $snapshot['id']) }}" class="mt-8">Ubah Bahan</x-button></div>
    @endif
    <p class="mt-8 max-w-2xl text-sm leading-7 text-muted">Ide masakan dihasilkan AI. Kesesuaian dihitung dari nama bahan, bukan kecukupan takaran. Periksa kembali seluruh bahan, alergi, dan kematangan sebelum dikonsumsi. Hasil tersedia selama 60 menit.</p>
</section>
@endsection
