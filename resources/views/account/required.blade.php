@extends('layouts.app')
@section('title', 'Akun diperlukan — Dapur Rantau')
@section('content')
<section class="page-container py-16">
    <div class="mx-auto max-w-3xl rounded-3xl border border-line bg-cream/50 p-8">
        <h1 class="section-title">Login / Daftar</h1>
        <p class="mt-5 leading-7 text-muted">Favorit, riwayat, dan pencarian setelah kuota guest habis memerlukan akun. Masuk atau buat akun, lalu verifikasi emailmu untuk melanjutkan.</p>
        <div class="mt-6 flex flex-wrap gap-3"><x-button href="{{ route('login') }}">Masuk</x-button><x-button href="{{ route('register') }}" variant="secondary">Daftar</x-button></div>
        <x-button href="{{ route('home') }}#cari-resep" class="mt-8">Kembali ke Dapur Rantau</x-button>
    </div>
</section>
@endsection
