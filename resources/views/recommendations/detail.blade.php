@extends('layouts.app')
@section('title', $recipe['title'].' — Dapur Rantau')
@section('content')
<section class="bg-cream/70 py-10 sm:py-14">
    <div class="page-container"><a href="{{ isset($saved) ? route('favorites.index') : route('recommendations.show', $snapshot['id']) }}" class="inline-flex rounded-lg text-sm font-semibold text-primary hover:underline">← {{ isset($saved) ? 'Kembali ke Favorit' : 'Kembali ke Rekomendasi' }}</a>
        <x-recipe-image :image="$recipe['image'] ?? null" :large="true" class="mt-8 max-w-3xl" />
        <div class="mt-8">@if(isset($saved))<span class="text-sm font-bold text-primary">Resep Favoritmu</span><p class="mt-2 text-sm text-muted">Ketersediaan bahan belum diperiksa untuk resep tersimpan ini.</p>@else<x-match-badge :match="$recipe['match']" />@endif</div>
        <h1 class="section-title max-w-3xl break-words sm:text-5xl">{{ $recipe['title'] }}</h1>
        <p class="mt-5 max-w-2xl leading-8 break-words text-muted">{{ $recipe['description'] }}</p>
        <div class="mt-6"><x-recipe-meta :recipe="$recipe" /></div>
    </div>
</section>
<div data-cooking-page class="page-container grid items-start gap-8 py-12 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
    <section class="rounded-3xl border border-line bg-white p-6 shadow-soft sm:p-8" aria-labelledby="ingredients-title">
        <p class="eyebrow">SIAPKAN DULU</p><h2 id="ingredients-title" class="mt-2 text-2xl font-bold">Bahan yang diperlukan</h2>
        @if(isset($checklist))
            <x-ingredient-checklist :checklist="$checklist" :snapshot="$snapshot" />
        @else
        <p class="mt-3 text-sm leading-6 text-muted">Centang bahan yang sudah disiapkan. Tanda centang hanya untuk membantumu memasak.</p>
        @php
            $groups = isset($saved)
                ? ['Periksa persediaanmu' => $recipe['match']['ingredients']]
                : ['Bahan tersedia' => $recipe['match']['available'], 'Bahan tambahan' => $recipe['match']['missing'], 'Bahan opsional' => $recipe['match']['optional']];
        @endphp
        @unless(isset($saved))<p class="mt-4 text-sm font-semibold">{{ $recipe['match']['missing_ingredients_count'] ? 'Butuh '.$recipe['match']['missing_ingredients_count'].' bahan tambahan' : 'Semua bahan tersedia' }}</p>@endunless
        @foreach ($groups as $heading => $ingredients)
        <h3 class="mt-6 font-bold">{{ $heading }}</h3>
        @if (!$ingredients)<p class="mt-2 text-sm text-muted">Tidak ada.</p>@endif
        <ul class="mt-2 divide-y divide-line">@foreach ($ingredients as $ingredient)
            <li><label class="flex cursor-pointer items-start gap-3 py-4"><input type="checkbox" data-cooking-ingredient data-required="{{ $ingredient['optional'] ? 'false' : 'true' }}" data-name="{{ $ingredient['name'] }}" class="peer mt-1 size-5 shrink-0 rounded accent-primary"><span class="min-w-0 flex-1 peer-checked:opacity-50"><span class="block font-semibold break-words">{{ $ingredient['name'] }}</span><span class="mt-1 block text-sm break-words text-muted">{{ $ingredient['quantity'] }}</span><span class="mt-2 block text-xs font-semibold {{ $ingredient['available'] ? 'text-primary' : 'text-ink' }}">{{ isset($saved) ? 'Periksa persediaanmu' : ($ingredient['available'] ? 'Tersedia' : ($ingredient['optional'] ? 'Belum tersedia' : 'Perlu dibeli')) }}{{ $ingredient['optional'] ? ' · Opsional' : '' }}</span></span></label></li>
        @endforeach</ul>
        @endforeach
        @endif
    </section>
    <div class="min-w-0">
        <x-cooking-guide :steps="app(\App\Support\RecipeStepNormalizer::class)->normalize($recipe['steps'])" />
        <aside class="mt-8 rounded-3xl bg-cream p-6 sm:p-8" aria-labelledby="tips-title"><h2 id="tips-title" class="flex items-center gap-3 text-xl font-bold"><x-icon name="sparkles" class="size-6 text-primary" />Tips memasak</h2><p class="mt-4 leading-8 break-words">{{ $recipe['tips'] }}</p></aside>
        <p class="mt-6 text-sm leading-7 text-muted">Periksa kembali kecukupan takaran, alergi, dan kematangan makanan. Pencocokan nama bahan tidak menjamin keamanan alergen atau turunannya.</p>
        <div class="mt-8 flex flex-wrap gap-3"><x-button href="{{ route('home') }}#cari-resep">Cari Resep Lain</x-button><x-favorite-button :recipe="$recipe" :search="$snapshot['id'] ?? null" /></div>
    </div>
</div>
@endsection
