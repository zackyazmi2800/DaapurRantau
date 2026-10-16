@extends('layouts.app')
@section('title', 'Resep Favoritku — Dapur Rantau')
@section('content')
<section class="page-container py-12 sm:py-16">
    <p class="eyebrow">MENU ANDALANMU</p><h1 class="section-title">Resep Favoritku</h1><x-visitor-notice />
    @if ($favorites->isEmpty())
        <div class="mt-10 rounded-3xl bg-cream p-8 text-center"><x-icon name="heart" class="mx-auto size-12 text-primary" /><h2 class="mt-5 text-2xl font-bold">Belum ada resep favorit nih! Yuk, cari resep yang cocok buat kamu.</h2><x-button href="{{ route('home') }}#cari-resep" class="mt-6">Cari Resep</x-button></div>
    @else
        <div class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">@foreach ($favorites as $favorite)
            <article class="flex min-w-0 flex-col rounded-3xl border border-line p-6 shadow-soft"><h2 class="text-2xl font-bold break-words">{{ $favorite->recipe_title }}</h2><p class="mt-3 text-sm leading-7 break-words text-muted">{{ $favorite->recipe_data['description'] }}</p><div class="my-5"><x-recipe-meta :recipe="$favorite->recipe_data" /></div><x-button href="{{ route('favorites.show', $favorite->id) }}" class="mt-auto">Lihat Resep</x-button><form class="mt-3" method="POST" action="{{ route('favorites.destroy', $favorite->id) }}">@csrf @method('DELETE')<x-button type="submit" variant="secondary" class="w-full" aria-label="Hapus favorit {{ $favorite->recipe_title }}">Hapus Favorit</x-button></form></article>
        @endforeach</div><div class="mt-8">{{ $favorites->links() }}</div>
    @endif
</section>
@endsection
