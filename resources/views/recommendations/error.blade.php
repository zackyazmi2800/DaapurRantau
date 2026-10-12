@extends('layouts.app')
@section('title', 'Rekomendasi belum tersedia — Dapur Rantau')
@section('content')
<section class="page-container py-16">
    <div class="mx-auto max-w-3xl rounded-3xl border border-line bg-cream/50 p-8">
        <h1 class="section-title">Rekomendasi belum tersedia</h1>
        <p role="alert" class="mt-5 leading-7 text-muted">{{ in_array($message, [\App\Exceptions\RecipeGenerationException::QUOTA, \App\Exceptions\RecipeGenerationException::INCOMPLETE, \App\Exceptions\RecipeGenerationException::CATEGORIES], true) ? $message : 'Maaf, rekomendasi belum tersedia. Silakan coba lagi.' }}</p>
        <x-button href="{{ route('home') }}#cari-resep" class="mt-8">Kembali ke bahanmu</x-button>
    </div>
</section>
@endsection
